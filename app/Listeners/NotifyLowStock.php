<?php

namespace App\Listeners;

use App\Events\LowStockDetected;
use App\Jobs\SendTelegramMessage;
use App\Models\Setting;

class NotifyLowStock
{
    public function handle(LowStockDetected $event): void
    {
        if (!Setting::get('telegram_enabled')) {
            return;
        }

        $product = $event->product;
        $chatId = Setting::get('telegram_admin_chat_id');

        if (!$chatId) {
            return;
        }

        if (!Setting::get('telegram_notify_low_stock')) {
            return;
        }

        $message = sprintf(
            "⚠️ <b>Stock bajo: %s</b>\n\n" .
            "📦 Cantidad actual: %d %s\n" .
            "📉 Mínimo configurado: %d\n" .
            "💰 Precio venta: %s\n\n" .
            "<i>Por favor revisa el inventario</i>",
            htmlspecialchars($product->name),
            $product->quantity,
            $product->unit?->symbol ?? 'uni',
            $product->min_stock,
            number_format($product->selling_price / 100, 2)
        );

        SendTelegramMessage::dispatch($chatId, $message);
    }
}
