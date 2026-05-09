<?php

namespace App\Services\Telegram;

use App\Models\Setting;
use App\Models\TelegramConversation;
use App\Models\Product;
use App\Services\Messaging\TelegramService;
use App\Services\Messaging\ProductSearchService;
use Illuminate\Support\Facades\Log;

class BotHandler
{
    public function __construct(
        protected TelegramService $telegram,
        protected ProductSearchService $searchService,
        protected BotProductHandler $productHandler,
        protected BotSaleHandler $saleHandler,
    ) {}

    public function dispatch(array $update): void
    {
        try {
            $message = $update['message'] ?? null;

            if (!$message) {
                return;
            }

            $chatId = (string) $message['from']['id'];

            // Check for active conversation first
            $conversation = TelegramConversation::where('chat_id', $chatId)
                ->where('step', '!=', 'idle')
                ->first();

            if ($conversation) {
                // Route to appropriate handler based on conversation type
                if (str_starts_with($conversation->step, 'nuevo:')) {
                    $this->productHandler->handle($chatId, $message);
                } elseif (str_starts_with($conversation->step, 'venta_rapida:')) {
                    $this->saleHandler->handle($chatId, $message);
                }
                return;
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
                    $this->handleQuickSaleFromSearch($chatId);
                } else {
                    // Fallback: free text = product search
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
            if ($productId) {
                $conversation = TelegramConversation::getOrCreate($chatId);
                $conversation->update([
                    'step' => 'busqueda:resultado',
                    'data' => ['product_id' => $productId],
                    'expires_at' => now()->addMinutes(5),
                ]);
            }

            $message = $results[0]['message'] . "\n\n";
            $message .= "<b>Opciones:</b>\n";
            $message .= "• Escribe <code>vender</code> para venta rápida\n";
            $message .= "• O busca otro producto";
            $this->telegram->sendMessage($chatId, $message);
        } else {
            // Multiple results
            $message = "📦 <b>Resultados de búsqueda para: {$text}</b>\n\n";
            foreach ($results as $idx => $result) {
                $message .= ($idx + 1) . ". <b>{$result['name']}</b> - {$result['price']}\n";
            }
            $message .= "\n<i>Escribe el nombre exacto para vender rápido o más detalles</i>";
            $this->telegram->sendMessage($chatId, $message);
        }
    }

    protected function handleQuickSaleFromSearch(string $chatId): void
    {
        $conversation = TelegramConversation::where('chat_id', $chatId)
            ->where('step', 'busqueda:resultado')
            ->first();

        if (!$conversation || empty($conversation->data['product_id'] ?? null)) {
            $this->telegram->sendMessage($chatId, "❌ Primero busca un producto con su nombre o SKU.");
            return;
        }

        $product = Product::find($conversation->data['product_id']);

        if (!$product) {
            $this->telegram->sendMessage($chatId, "❌ Producto no encontrado.");
            $conversation->delete();
            return;
        }

        // Start quick sale flow
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
            default => $this->telegram->sendMessage($chatId, "❓ Comando no reconocido. Escribe /ayuda para ver opciones."),
        };
    }

    protected function cmdHelp(string $chatId): void
    {
        $message = "<b>📚 Ayuda - Comandos disponibles</b>\n\n" .
            "/stock — Ver productos en stock crítico\n" .
            "/ventas — Resumen de ventas de hoy\n" .
            "/nuevo — Registrar un nuevo producto\n" .
            "/listar — Listar productos (todas categorías o filtrar)\n\n" .
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
}
