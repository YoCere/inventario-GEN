<?php

namespace App\Services\Agent\Tools;

use App\Models\Product;
use App\Models\TelegramConversation;
use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;
use App\Services\Messaging\TelegramService;

class StartSaleTool extends BaseTool
{
    public function __construct(private TelegramService $telegram) {}

    public function name(): string
    {
        return 'start_sale';
    }

    public function description(): string
    {
        return 'Inicia el flujo de venta para un producto. Llama SOLO cuando el usuario ya confirmó que quiere vender. Muestra la ficha del producto con opciones de venta.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_id' => [
                    'type' => 'integer',
                    'description' => 'ID del producto a vender (obtenido de search_products)',
                ],
            ],
            'required' => ['product_id'],
        ];
    }

    public function execute(array $input, AgentContext $context): array
    {
        $productId = (int) ($input['product_id'] ?? 0);
        $product = Product::with('unit')->find($productId);

        if (!$product) {
            return ['error' => 'Producto no encontrado con ID ' . $productId];
        }

        // Set conversation state so BotHandler's existing sale flow takes over
        $conversation = TelegramConversation::getOrCreate($context->chatId);
        $conversation->update([
            'step'       => 'busqueda:resultado',
            'data'       => ['product_id' => $productId],
            'expires_at' => now()->addMinutes(5),
        ]);

        // Send product card with sell options (same format as BotHandler::handleSearch)
        $unit  = $product->unit?->symbol ?? 'uni';
        $price = number_format($product->selling_price / 100, 2);
        $stock = $product->quantity <= $product->min_stock ? '⚠️ ' : '';

        $msg = "📦 <b>{$product->name}</b>\n"
            . "💰 Precio: {$price}\n"
            . "📊 Stock: {$stock}{$product->quantity} {$unit}\n"
            . "SKU: {$product->sku}\n\n"
            . "<b>Opciones:</b>\n1️⃣ Vender\n2️⃣ Buscar otro producto";

        $this->telegram->sendMessage($context->chatId, $msg);

        return ['status' => 'ok', 'product' => $product->name];
    }
}
