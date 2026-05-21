<?php

namespace App\Services\Telegram;

use App\Models\TelegramConversation;
use App\Models\Category;
use App\DTOs\CategoryData;
use App\DTOs\ProductData;
use App\Services\CategoryService;
use App\Services\Messaging\TelegramService;
use App\Services\ProductService;
use App\Support\NumberParser;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class BotProductHandler
{
    public function __construct(
        protected TelegramService $telegram,
        protected ProductService $productService,
        protected CategoryService $categoryService,
    ) {}

    public function handle(string $chatId, array $message): void
    {
        $conversation = TelegramConversation::getOrCreate($chatId);
        $text = trim($message['text'] ?? '');

        // Check for cancel command
        if (strtolower($text) === '/cancelar' || strtolower($text) === 'cancel') {
            $conversation->delete();
            $this->telegram->sendMessage($chatId, "❌ Operación cancelada.");
            return;
        }

        // Route to appropriate handler
        if (str_starts_with($conversation->step, 'nuevo:')) {
            $this->handleNuevoFlow($chatId, $conversation, $message, $text);
        }
    }

    public function start(string $chatId): void
    {
        $conversation = TelegramConversation::getOrCreate($chatId);
        $conversation->update([
            'step' => 'nuevo:nombre',
            'data' => [],
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "📝 <b>Registro de nuevo producto</b>\n\n" .
            "¿Cuál es el <b>nombre del producto</b>?\n\n" .
            "(Escribe /cancelar para salir)"
        );
    }

    private function handleNuevoFlow(string $chatId, TelegramConversation $conversation, array $message, string $text): void
    {
        $step = $conversation->step;

        match ($step) {
            'nuevo:nombre' => $this->askNombre($chatId, $conversation, $text),
            'nuevo:categoria' => $this->askCategoria($chatId, $conversation, $text),
            'nuevo:precio_compra' => $this->askPrecioCompra($chatId, $conversation, $text),
            'nuevo:precio_venta' => $this->askPrecioVenta($chatId, $conversation, $text),
            'nuevo:cantidad' => $this->askCantidad($chatId, $conversation, $text),
            'nuevo:foto' => $this->askFoto($chatId, $conversation, $message),
            'nuevo:confirmar' => $this->confirm($chatId, $conversation, $text),
            default => $this->telegram->sendMessage($chatId, "❓ Estado desconocido. /cancelar y reintenta."),
        };
    }

    private function askNombre(string $chatId, TelegramConversation $conversation, string $nombre): void
    {
        if (empty($nombre) || strlen($nombre) < 3) {
            $this->telegram->sendMessage($chatId, "❌ El nombre debe tener al menos 3 caracteres.");
            return;
        }

        $data = $conversation->data ?? [];
        $data['nombre'] = $nombre;

        $conversation->update([
            'step' => 'nuevo:categoria',
            'data' => $data,
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->telegram->sendMessage($chatId, "📦 Nombre: <b>{$nombre}</b>\n\n" . $this->buildCategoryPrompt());
    }

    private function buildCategoryPrompt(): string
    {
        $categories = Category::orderBy('name')->limit(12)->get();
        $total = Category::count();

        $msg = "¿Cuál es la <b>categoría</b>?\n\n";

        if ($categories->isEmpty()) {
            $msg .= "No hay categorías aún. Escribe el nombre de la nueva categoría.";
        } else {
            foreach ($categories as $idx => $cat) {
                $msg .= ($idx + 1) . ". {$cat->name}\n";
            }
            if ($total > 12) {
                $msg .= "... ({$total} en total)\n";
            }
            $msg .= "\nEscribe el <b>número</b> o el <b>nombre</b>.\n";
            $msg .= "<i>Si no existe la categoría, escríbela y te pregunto si crear.</i>";
        }

        return $msg;
    }

    private function askCategoria(string $chatId, TelegramConversation $conversation, string $input): void
    {
        $data      = $conversation->data ?? [];
        $inputLow  = mb_strtolower(trim($input));

        // ── Pending create confirmation ──────────────────────────────────────
        if (!empty($data['categoria_pending'])) {
            $pendingName = $data['categoria_pending'];

            if (\in_array($inputLow, ['si', 'sí', 's', 'yes', '1'], true)) {
                $this->createAndAdvanceCategory($chatId, $conversation, $data, $pendingName);
                return;
            }

            if (\in_array($inputLow, ['no', 'n', '2'], true)) {
                unset($data['categoria_pending']);
                $conversation->update(['data' => $data]);
                $this->telegram->sendMessage($chatId, $this->buildCategoryPrompt());
                return;
            }

            // User typed a new name instead → clear pending, fall through to search
            unset($data['categoria_pending']);
            $conversation->update(['data' => $data]);
        }

        // ── Number selection ─────────────────────────────────────────────────
        if (ctype_digit(trim($input))) {
            $idx      = (int) $input - 1;
            $category = Category::orderBy('name')->offset($idx)->limit(1)->first();
            if ($category) {
                $this->setCategoryAndAdvance($chatId, $conversation, $data, $category);
                return;
            }
        }

        // ── Text search (fuzzy) ──────────────────────────────────────────────
        $category = $this->findCategoryByText($input);
        if ($category) {
            $this->setCategoryAndAdvance($chatId, $conversation, $data, $category);
            return;
        }

        // ── Not found → ask to create ────────────────────────────────────────
        $similar = Category::whereRaw('LOWER(name) LIKE ?', ['%' . $inputLow . '%'])
            ->orderBy('name')
            ->limit(3)
            ->get();

        $data['categoria_pending'] = $input;
        $conversation->update(['data' => $data, 'expires_at' => now()->addMinutes(30)]);

        $msg = "❓ No encontré \"<b>{$input}</b>\".\n\n";
        if ($similar->isNotEmpty()) {
            $msg .= "¿Quisiste decir?\n";
            foreach ($similar as $cat) {
                $msg .= "• {$cat->name}\n";
            }
            $msg .= "\n";
        }
        $msg .= "¿Crear la categoría <b>\"{$input}\"</b>?\n<i>Responde sí/no o escribe otro nombre.</i>";

        $this->telegram->sendMessage($chatId, $msg);
    }

    private function findCategoryByText(string $input): ?Category
    {
        // Exact match (case-insensitive)
        $cat = Category::whereRaw('LOWER(name) = LOWER(?)', [$input])->first();
        if ($cat) {
            return $cat;
        }

        // Contains match
        $cat = Category::whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($input) . '%'])->first();
        if ($cat) {
            return $cat;
        }

        // Normalized (accent-stripped) match
        $normalized = mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $input) ?: $input);
        if ($normalized !== mb_strtolower($input)) {
            $all = Category::orderBy('name')->get();
            foreach ($all as $cat) {
                $catNorm = mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $cat->name) ?: $cat->name);
                if (str_contains($catNorm, $normalized)) {
                    return $cat;
                }
            }
        }

        return null;
    }

    private function setCategoryAndAdvance(string $chatId, TelegramConversation $conversation, array $data, Category $category): void
    {
        $data['categoria_id']     = $category->id;
        $data['categoria_nombre'] = $category->name;
        unset($data['categoria_pending']);

        $conversation->update([
            'step'       => 'nuevo:precio_compra',
            'data'       => $data,
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "✅ Categoría: <b>{$category->name}</b>\n\n" .
            "¿Cuál es el <b>precio de compra</b>? (número, ej: 25.50)"
        );
    }

    private function createAndAdvanceCategory(string $chatId, TelegramConversation $conversation, array $data, string $name): void
    {
        try {
            $name = trim($name);
            $slug = Str::slug($name);

            // Ensure unique slug
            $base = $slug;
            $n    = 1;
            while (Category::where('slug', $slug)->exists()) {
                $slug = $base . '-' . $n++;
            }

            $category = $this->categoryService->createCategory(
                CategoryData::fromArray(['name' => $name, 'slug' => $slug])
            );

            $this->telegram->sendMessage($chatId, "✅ Categoría creada: <b>{$name}</b>");
            $this->setCategoryAndAdvance($chatId, $conversation, $data, $category);
        } catch (\Exception $e) {
            Log::error('Category creation failed in bot', ['error' => $e->getMessage()]);
            $this->telegram->sendMessage($chatId, "❌ Error al crear categoría: " . $e->getMessage() . "\n\nIntenta de nuevo.");
        }
    }

    private function askPrecioCompra(string $chatId, TelegramConversation $conversation, string $input): void
    {
        $price = $this->parsePrice($input);

        if ($price === null) {
            $this->telegram->sendMessage($chatId, "❌ Precio inválido. Ingresa un número (ej: 1500)");
            return;
        }

        $data = $conversation->data ?? [];
        $data['precio_compra'] = $price;

        $conversation->update([
            'step' => 'nuevo:precio_venta',
            'data' => $data,
            'expires_at' => now()->addMinutes(30),
        ]);

        $formatted = number_format($price / 100, 2);
        $this->telegram->sendMessage(
            $chatId,
            "💰 Precio de compra: <b>{$formatted}</b>\n\n" .
            "¿Cuál es el <b>precio de venta</b>?"
        );
    }

    private function askPrecioVenta(string $chatId, TelegramConversation $conversation, string $input): void
    {
        $price = $this->parsePrice($input);

        if ($price === null) {
            $this->telegram->sendMessage($chatId, "❌ Precio inválido. Ingresa un número.");
            return;
        }

        $data = $conversation->data ?? [];
        $data['precio_venta'] = $price;

        $conversation->update([
            'step' => 'nuevo:cantidad',
            'data' => $data,
            'expires_at' => now()->addMinutes(30),
        ]);

        $formatted = number_format($price / 100, 2);
        $this->telegram->sendMessage(
            $chatId,
            "💵 Precio de venta: <b>{$formatted}</b>\n\n" .
            "¿Cuál es la <b>cantidad inicial en stock</b>?"
        );
    }

    private function askCantidad(string $chatId, TelegramConversation $conversation, string $input): void
    {
        $qty = NumberParser::extractInt($input);
        if ($qty === null || $qty < 0) {
            $this->telegram->sendMessage($chatId, "❌ Cantidad inválida. Escribe un número (ej: 10, diez)");
            return;
        }

        $data = $conversation->data ?? [];
        $data['cantidad'] = $qty;

        $conversation->update([
            'step' => 'nuevo:foto',
            'data' => $data,
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "📊 Cantidad: <b>{$qty}</b>\n\n" .
            "Envía una foto del producto (opcional)\n" .
            "Escribe 'omitir' si no tienes foto"
        );
    }

    private function askFoto(string $chatId, TelegramConversation $conversation, array $message): void
    {
        $data = $conversation->data ?? [];

        // Check if text message (omitir)
        if (isset($message['text'])) {
            if (strtolower(trim($message['text'])) === 'omitir') {
                $data['foto_path'] = null;
                $this->showConfirm($chatId, $conversation, $data);
                return;
            }
            $this->telegram->sendMessage($chatId, "❌ Envía una foto o escribe 'omitir'.");
            return;
        }

        // Handle photo
        if (isset($message['photo'])) {
            try {
                $photo = end($message['photo']); // Get best quality
                $fileId = $photo['file_id'];

                Log::info('Processing photo upload', ['file_id' => $fileId]);

                // Download photo from Telegram
                $filePath = $this->telegram->getFile($fileId);
                $content = $this->telegram->downloadFile($filePath);

                if (empty($content)) {
                    throw new \Exception('Downloaded content is empty');
                }

                // Detect MIME type
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_buffer($finfo, $content);
                finfo_close($finfo);

                $extension = match($mimeType) {
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    default => 'jpg',
                };

                // Ensure directory exists
                Storage::disk('public')->makeDirectory('products');

                // Store in storage
                $storagePath = 'products/' . Str::uuid() . '.' . $extension;
                Storage::disk('public')->put($storagePath, $content);

                Log::info('Photo stored successfully', ['path' => $storagePath, 'mime' => $mimeType]);

                $data['foto_path'] = $storagePath;
                $this->showConfirm($chatId, $conversation, $data);
            } catch (\Exception $e) {
                Log::error('Photo download/storage error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $data['foto_path'] = null;
                $this->telegram->sendMessage($chatId, "⚠️ Error: " . $e->getMessage() . "\n\nContinuando sin imagen...");
                $this->showConfirm($chatId, $conversation, $data);
            }
            return;
        }

        $this->telegram->sendMessage($chatId, "❌ Envía una foto o escribe 'omitir'.");
    }

    private function showConfirm(string $chatId, TelegramConversation $conversation, array $data): void
    {
        $conversation->update([
            'step' => 'nuevo:confirmar',
            'data' => $data,
            'expires_at' => now()->addMinutes(30),
        ]);

        $precioCompra = number_format($data['precio_compra'] / 100, 2);
        $precioVenta = number_format($data['precio_venta'] / 100, 2);

        $message = "📋 <b>Resumen del producto</b>\n\n";
        $message .= "Nombre: <b>{$data['nombre']}</b>\n";
        $message .= "Categoría: {$data['categoria_nombre']}\n";
        $message .= "Precio compra: {$precioCompra}\n";
        $message .= "Precio venta: {$precioVenta}\n";
        $message .= "Stock inicial: {$data['cantidad']}\n";
        $message .= "Foto: " . (isset($data['foto_path']) && $data['foto_path'] ? "✅ Sí" : "❌ No") . "\n\n";
        $message .= "<b>¿Confirmar crear producto?</b>\n\n";
        $message .= "Responde: 'sí' o 'no'";

        $this->telegram->sendMessage($chatId, $message);
    }

    private function confirm(string $chatId, TelegramConversation $conversation, string $text): void
    {
        if (strtolower($text) !== 'sí' && strtolower($text) !== 'si') {
            $conversation->delete();
            $this->telegram->sendMessage($chatId, "❌ Producto no creado.");
            return;
        }

        $data = $conversation->data ?? [];

        try {
            $productData = ProductData::fromArray([
                'category_id' => $data['categoria_id'],
                'unit_id' => 1, // Default unit for MVP - TODO: allow unit selection
                'sku' => null, // Auto-generate
                'name' => $data['nombre'],
                'purchase_price' => $data['precio_compra'],
                'selling_price' => $data['precio_venta'],
                'quantity' => $data['cantidad'],
                'min_stock' => max(1, intval($data['cantidad'] * 0.2)), // 20% of initial qty
                'is_active' => true,
                'description' => null,
                'notes' => "Creado vía Telegram",
                'image_path' => $data['foto_path'] ?? null,
            ]);

            $product = $this->productService->createProduct($productData);

            $conversation->delete();
            $this->telegram->sendMessage(
                $chatId,
                "✅ <b>Producto creado!</b>\n\n" .
                "SKU: {$product->sku}\n" .
                "Nombre: {$product->name}\n\n" .
                "Ya está disponible en tu inventario."
            );
        } catch (\Exception $e) {
            Log::error('Product creation error', ['error' => $e->getMessage()]);
            $this->telegram->sendMessage($chatId, "❌ Error al crear el producto. Intenta de nuevo o /cancelar.");
        }
    }

    private function parsePrice(string $input): ?int
    {
        $value = NumberParser::extractFloat($input);
        if ($value === null || $value <= 0) {
            return null;
        }
        return (int) round($value * 100);
    }
}
