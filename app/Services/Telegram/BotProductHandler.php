<?php

namespace App\Services\Telegram;

use App\Models\TelegramConversation;
use App\Models\Category;
use App\Models\Product;
use App\Services\Messaging\TelegramService;
use App\Services\ProductService;
use App\DTOs\ProductData;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class BotProductHandler
{
    public function __construct(
        protected TelegramService $telegram,
        protected ProductService $productService,
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
        $data = $conversation->data ?? [];

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

        // Get categories for next step
        $categories = Category::orderBy('name')->get();
        $conversation->update([
            'step' => 'nuevo:categoria',
            'data' => $data,
            'expires_at' => now()->addMinutes(30),
        ]);

        $message = "📦 Nombre: <b>{$nombre}</b>\n\n";
        $message .= "Ahora, ¿cuál es la <b>categoría</b>?\n\n";

        if ($categories->count() <= 5) {
            // Show numbered list
            foreach ($categories as $idx => $cat) {
                $message .= ($idx + 1) . ". {$cat->name}\n";
            }
            $message .= "\nResponde con el número";
        } else {
            // Ask to type
            $message .= "Escribe el nombre de la categoría (ej: Electrónica)";
        }

        $this->telegram->sendMessage($chatId, $message);
    }

    private function askCategoria(string $chatId, TelegramConversation $conversation, string $input): void
    {
        $data = $conversation->data ?? [];
        $category = null;

        // Try by number first
        $num = (int) $input;
        if ($num > 0) {
            $category = Category::orderBy('name')->skip($num - 1)->first();
        }

        // Try by name
        if (!$category) {
            $category = Category::where('name', 'LIKE', "%{$input}%")->first();
        }

        if (!$category) {
            $this->telegram->sendMessage($chatId, "❌ Categoría no encontrada. Intenta de nuevo.");
            return;
        }

        $data['categoria_id'] = $category->id;
        $data['categoria_nombre'] = $category->name;

        $conversation->update([
            'step' => 'nuevo:precio_compra',
            'data' => $data,
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "✅ Categoría: <b>{$category->name}</b>\n\n" .
            "¿Cuál es el <b>precio de compra</b>? (número, ej: 1500)"
        );
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
        $qty = (int) $input;

        if ($qty < 0) {
            $this->telegram->sendMessage($chatId, "❌ Cantidad inválida.");
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

                // Download photo from Telegram
                $filePath = $this->telegram->getFile($fileId);
                $content = $this->telegram->downloadFile($filePath);

                // Store in storage
                $storagePath = 'products/' . Str::uuid() . '.jpg';
                Storage::disk('public')->put($storagePath, $content);

                $data['foto_path'] = $storagePath;
                $this->showConfirm($chatId, $conversation, $data);
            } catch (\Exception $e) {
                Log::error('Photo download error', ['error' => $e->getMessage()]);
                $this->telegram->sendMessage($chatId, "❌ Error al descargar la foto. Intenta de nuevo.");
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
            $conversation->delete();
            $this->telegram->sendMessage(
                $chatId,
                "❌ Error al crear el producto.\n\n" .
                "Error: " . $e->getMessage()
            );
        }
    }

    private function parsePrice(string $input): ?int
    {
        $input = trim(str_replace(',', '.', $input));

        if (!is_numeric($input)) {
            return null;
        }

        // Convert to cents (multiply by 100)
        return (int) round(floatval($input) * 100);
    }
}
