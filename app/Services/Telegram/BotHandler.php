<?php

namespace App\Services\Telegram;

use App\Models\Setting;
use App\Models\TelegramConversation;
use App\Models\Product;
use App\Services\Messaging\TelegramService;
use App\Services\Messaging\ProductSearchService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BotHandler
{
    public function __construct(
        protected TelegramService $telegram,
        protected ProductSearchService $searchService,
        protected BotProductHandler $productHandler,
        protected BotSaleHandler $saleHandler,
        protected BotRefundHandler $refundHandler,
        protected BotAuthHandler $authHandler,
    ) {}

    public function dispatch(array $update): void
    {
        try {
            $message = $update['message'] ?? null;

            if (!$message) {
                return;
            }

            $chatId = (string) $message['from']['id'];

            // CHECK AUTHENTICATION FIRST
            $conversation = TelegramConversation::where('chat_id', $chatId)
                ->where('step', '!=', 'idle')
                ->first();

            // Handle auth conversations
            if ($conversation && str_starts_with($conversation->step, 'auth:')) {
                $this->authHandler->handle($chatId, $message);
                return;
            }

            // Check if user is authenticated
            if (!$this->authHandler->isAuthenticated($chatId)) {
                // Not authenticated - start login
                $this->authHandler->startLogin($chatId);
                return;
            }

            // USER IS AUTHENTICATED - continue with bot logic

            if ($conversation) {
                // Route to appropriate handler based on conversation type
                if (str_starts_with($conversation->step, 'nuevo:')) {
                    $this->productHandler->handle($chatId, $message);
                    return;
                } elseif (str_starts_with($conversation->step, 'venta_rapida:')) {
                    $this->saleHandler->handle($chatId, $message);
                    return;
                } elseif (str_starts_with($conversation->step, 'devolver:')) {
                    // Handle refund flow
                    $text = trim($message['text'] ?? '');
                    if ($conversation->step === 'devolver:seleccionar') {
                        $this->refundHandler->handle($chatId, $message);
                    } elseif ($conversation->step === 'devolver:confirmar') {
                        if ($text === '1') {
                            $this->refundHandler->confirmRefund($chatId, $conversation);
                        } else {
                            $conversation->delete();
                            $this->telegram->sendMessage($chatId, "❌ Devolución cancelada.");
                        }
                    }
                    return;
                } elseif ($conversation->step === 'busqueda:resultado') {
                    // Handle options after single search result
                    $text = trim($message['text'] ?? '');
                    if ($text === '1' || strtolower($text) === 'vender') {
                        Log::info('User selected option 1: vender', ['chatId' => $chatId]);
                        $this->handleQuickSaleFromSearch($chatId);
                        return;
                    } elseif ($text === '2') {
                        Log::info('User selected option 2: buscar otro', ['chatId' => $chatId]);
                        $conversation->delete();
                    } else {
                        // Invalid option, treat as new search
                        $conversation->delete();
                    }
                } elseif ($conversation->step === 'busqueda:multiple') {
                    // Handle number selection from multiple results
                    $text = trim($message['text'] ?? '');
                    $this->handleMultipleResultSelection($chatId, $conversation, $text);
                    return;
                }
            }

            if (empty($message['text'])) {
                return;
            }

            $text = trim($message['text']);

            if (str_starts_with($text, '/')) {
                $this->handleCommand($chatId, $text);
            } else {
                // Check if trying to sell from recent search
                if (strtolower($text) === 'vender') {
                    Log::info('User requested quick sale', ['chatId' => $chatId]);
                    $this->handleQuickSaleFromSearch($chatId);
                } else {
                    // Fallback: free text = product search
                    Log::info('Searching product', ['query' => $text, 'chatId' => $chatId]);
                    $this->handleSearch($chatId, $text);
                }
            }
        } catch (\Exception $e) {
            Log::error('Bot dispatch error', ['error' => $e->getMessage()]);
        }
    }

    protected function handleSearch(string $chatId, string $text): void
    {
        $results = $this->searchService->search($text);

        if (empty($results)) {
            $this->telegram->sendMessage($chatId, "❌ No se encontró ningún producto con \"<i>{$text}</i>\"");
            return;
        }

        if (count($results) === 1) {
            // Single result - store product ID for quick sale
            $productId = $results[0]['id'] ?? null;
            Log::info('Single result found', ['productId' => $productId, 'chatId' => $chatId]);

            if ($productId) {
                $conversation = TelegramConversation::getOrCreate($chatId);
                $updated = $conversation->update([
                    'step' => 'busqueda:resultado',
                    'data' => ['product_id' => $productId],
                    'expires_at' => now()->addMinutes(5),
                ]);
                Log::info('Conversation updated', [
                    'updated' => $updated,
                    'step' => $conversation->step,
                    'data' => $conversation->data,
                ]);
            }

            $message = $results[0]['message'] . "\n\n";
            $message .= "<b>Opciones:</b>\n";
            $message .= "1️⃣ Vender\n";
            $message .= "2️⃣ Buscar otro producto";
            $this->sendProductCard($chatId, $results[0], $message);
        } else {
            // Multiple results - store in conversation for selection by number
            $conversation = TelegramConversation::getOrCreate($chatId);
            $conversation->update([
                'step' => 'busqueda:multiple',
                'data' => ['results' => $results],
                'expires_at' => now()->addMinutes(5),
            ]);

            $message = "📦 <b>Resultados de búsqueda para: {$text}</b>\n\n";
            foreach ($results as $idx => $result) {
                $message .= ($idx + 1) . ". <b>{$result['name']}</b> - {$result['price']}\n";
            }
            $message .= "\n<i>Escribe el número para vender rápido (Ej: 1, 2, 3...)</i>";
            $this->telegram->sendMessage($chatId, $message);
        }
    }

    protected function handleMultipleResultSelection(string $chatId, TelegramConversation $conversation, string $input): void
    {
        $results = $conversation->data['results'] ?? [];
        $trimmed = trim($input);

        // Si input no es entero puro, tratarlo como nueva búsqueda
        if (!ctype_digit($trimmed)) {
            Log::info('Non-numeric input in multi-results, treating as new search', [
                'chatId' => $chatId,
                'input' => $trimmed,
            ]);
            $conversation->delete();
            $this->handleSearch($chatId, $trimmed);
            return;
        }

        $index = (int) $trimmed - 1;

        Log::info('User selected result', ['chatId' => $chatId, 'index' => $index, 'total' => count($results)]);

        if ($index < 0 || $index >= count($results)) {
            $this->telegram->sendMessage($chatId, "❌ Número inválido. Escribe un número entre 1 y " . count($results) . " o escribe otro nombre para buscar.");
            return;
        }

        $selectedResult = $results[$index];
        $productId = $selectedResult['id'] ?? null;

        if (!$productId) {
            $this->telegram->sendMessage($chatId, "❌ Producto no encontrado.");
            $conversation->delete();
            return;
        }

        // Store selected product and show info with vender option
        $conversation->update([
            'step' => 'busqueda:resultado',
            'data' => ['product_id' => $productId],
            'expires_at' => now()->addMinutes(5),
        ]);

        $message = $selectedResult['message'] . "\n\n";
        $message .= "<b>Opciones:</b>\n";
        $message .= "1️⃣ Vender\n";
        $message .= "2️⃣ Buscar otro producto";
        $this->sendProductCard($chatId, $selectedResult, $message);
    }

    /**
     * Send product card with image if available, fallback to text-only message.
     * Telegram photo caption limit: 1024 chars. If exceeds, splits photo + follow-up text.
     */
    protected function sendProductCard(string $chatId, array $product, string $message): void
    {
        $imagePath = $product['image_path'] ?? null;

        // No image → plain text
        if (!$imagePath || !Storage::disk('public')->exists($imagePath)) {
            $this->telegram->sendMessage($chatId, $message);
            return;
        }

        try {
            // Telegram photo caption max 1024 chars
            if (mb_strlen($message) <= 1024) {
                $this->telegram->sendPhoto($chatId, $imagePath, $message);
            } else {
                // Photo with truncated caption + full text follow-up
                $this->telegram->sendPhoto($chatId, $imagePath, $product['name'] ?? '');
                $this->telegram->sendMessage($chatId, $message);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send product photo, fallback to text', [
                'chat_id' => $chatId,
                'image_path' => $imagePath,
                'error' => $e->getMessage(),
            ]);
            $this->telegram->sendMessage($chatId, $message);
        }
    }

    protected function handleQuickSaleFromSearch(string $chatId): void
    {
        Log::info('handleQuickSaleFromSearch called', ['chatId' => $chatId]);

        $conversation = TelegramConversation::where('chat_id', $chatId)
            ->where('step', 'busqueda:resultado')
            ->first();

        Log::info('Looking for conversation', [
            'chatId' => $chatId,
            'found' => !!$conversation,
            'step' => $conversation?->step,
            'data' => $conversation?->data,
        ]);

        if (!$conversation || empty($conversation->data['product_id'] ?? null)) {
            Log::warning('No conversation or product_id found', ['chatId' => $chatId]);
            $this->telegram->sendMessage($chatId, "❌ Primero busca un producto con su nombre o SKU.");
            return;
        }

        $product = Product::find($conversation->data['product_id']);

        Log::info('Product lookup', [
            'product_id' => $conversation->data['product_id'],
            'found' => !!$product,
        ]);

        if (!$product) {
            $this->telegram->sendMessage($chatId, "❌ Producto no encontrado.");
            $conversation->delete();
            return;
        }

        Log::info('Starting quick sale', ['product_id' => $product->id, 'product_name' => $product->name]);
        $this->saleHandler->startQuickSale($chatId, $product);
    }

    protected function handleCommand(string $chatId, string $text): void
    {
        $parts = explode(' ', $text, 2);
        $command = strtolower($parts[0]);
        $args = $parts[1] ?? '';

        match ($command) {
            '/ayuda', '/help', '/start' => $this->cmdHelp($chatId),
            '/stock' => $this->cmdStock($chatId),
            '/ventas' => $this->cmdSales($chatId),
            '/nuevo' => $this->cmdNewProduct($chatId),
            '/listar' => $this->cmdList($chatId, $args),
            '/devolver' => $this->cmdRefund($chatId),
            default => $this->telegram->sendMessage($chatId, "❓ Comando no reconocido. Escribe /ayuda para ver opciones."),
        };
    }

    protected function cmdHelp(string $chatId): void
    {
        $message = "<b>📚 Ayuda - Comandos disponibles</b>\n\n" .
            "/stock — Ver productos en stock crítico\n" .
            "/ventas — Resumen de ventas de hoy\n" .
            "/nuevo — Registrar un nuevo producto\n" .
            "/listar — Listar productos (todas categorías o filtrar)\n" .
            "/devolver — Procesar devoluciones\n\n" .
            "<b>💡 Búsqueda directa</b>\n" .
            "Escribe el nombre de un producto y te mostraré el precio y stock.\n\n" .
            "Ej: <code>Redmi 14c</code>\n\n" .
            "<b>Filtros en /listar</b>\n" .
            "/listar — Todas\n" .
            "/listar categoría — Por categoría\n" .
            "/listar bajo — Stock bajo\n" .
            "/listar activos — Solo activos";

        $this->telegram->sendMessage($chatId, $message);
    }

    protected function cmdStock(string $chatId): void
    {
        $products = \App\Models\Product::where('is_active', true)
            ->whereRaw('quantity <= min_stock')
            ->orderBy('quantity')
            ->get();

        if ($products->isEmpty()) {
            $this->telegram->sendMessage($chatId, "✅ Todos los productos tienen stock suficiente");
            return;
        }

        $message = "⚠️ <b>Stock crítico</b> ({$products->count()} productos)\n\n";
        foreach ($products->take(10) as $product) {
            $unit = $product->unit?->symbol ?? 'uni';
            $message .= "• <b>{$product->name}</b>\n   {$product->quantity} {$unit} (mín: {$product->min_stock})\n";
        }

        if ($products->count() > 10) {
            $message .= "\n... y " . ($products->count() - 10) . " más";
        }

        $this->telegram->sendMessage($chatId, $message);
    }

    protected function cmdSales(string $chatId): void
    {
        $today = now()->toDate();
        $sales = \App\Models\Sale::whereDate('created_at', $today)
            ->where('status', 'completed')
            ->get();

        $message = "💰 <b>Ventas del día (" . $today->format('d/m/Y') . ")</b>\n\n";
        $message .= "Transacciones: {$sales->count()}\n";
        $message .= "Total: " . number_format($sales->sum('total') / 100, 2);

        $this->telegram->sendMessage($chatId, $message);
    }

    protected function cmdNewProduct(string $chatId): void
    {
        $this->productHandler->start($chatId);
    }

    protected function cmdList(string $chatId, string $filter): void
    {
        $filter = strtolower(trim($filter));

        $query = \App\Models\Product::where('is_active', true);

        // Aplicar filtro
        if ($filter === 'bajo') {
            $query->whereRaw('quantity <= min_stock');
            $title = "⚠️ <b>Stock bajo</b>";
        } elseif ($filter === 'activos') {
            $title = "✅ <b>Productos activos</b>";
        } elseif (!empty($filter)) {
            // Filtro por categoría
            $query->whereHas('category', function ($q) use ($filter) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$filter}%"]);
            });
            $title = "📦 <b>Categoría: {$filter}</b>";
        } else {
            $title = "📦 <b>Todos los productos</b>";
        }

        $products = $query->orderBy('name')->limit(15)->get();

        if ($products->isEmpty()) {
            $this->telegram->sendMessage($chatId, "❌ No hay productos con ese filtro");
            return;
        }

        $message = "{$title}\n\n";
        foreach ($products as $product) {
            $unit = $product->unit?->symbol ?? 'uni';
            $price = number_format($product->selling_price / 100, 2);
            $badge = $product->quantity <= $product->min_stock ? '⚠️ ' : '✓ ';
            $message .= "{$badge}<b>{$product->name}</b>\n" .
                "   Precio: {$price} | Stock: {$product->quantity} {$unit}\n";
        }

        if ($products->count() >= 15) {
            $message .= "\n... (mostrando 15 primeros)";
        }

        $this->telegram->sendMessage($chatId, $message);
    }

    protected function cmdRefund(string $chatId): void
    {
        $this->refundHandler->start($chatId);
    }
}
