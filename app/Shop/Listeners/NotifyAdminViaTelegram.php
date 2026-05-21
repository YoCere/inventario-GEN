<?php

namespace App\Shop\Listeners;

use App\Models\Setting;
use App\Services\Messaging\TelegramService;
use App\Shop\Events\WebReservationCreated;
use Illuminate\Support\Facades\Log;

/**
 * Listener: notifica al admin por Telegram cuando llega una reserva nueva.
 * Reusa TelegramService existente del proyecto. Si Telegram está deshabilitado
 * o no hay chat_id configurado, el listener no hace nada (no rompe el flujo).
 */
class NotifyAdminViaTelegram
{
    public function __construct(private TelegramService $telegram) {}

    public function handle(WebReservationCreated $event): void
    {
        if (Setting::get('telegram_enabled') !== '1') {
            return;
        }

        $chatId = (string) Setting::get('telegram_admin_chat_id', '');
        if ($chatId === '') {
            return;
        }

        $sale = $event->sale->loadMissing(['items.product']);

        $businessName = Setting::get('shop_business_name') ?: config('app.name');
        $currency = Setting::get('shop_currency_symbol', 'Bs.');

        $lines = [];
        $lines[] = "🛒 <b>Nueva reserva web — {$businessName}</b>";
        $lines[] = "Pedido: <b>" . htmlspecialchars($sale->invoice_number, ENT_QUOTES) . "</b>";
        $lines[] = '';

        if ($sale->buyer_name) {
            $lines[] = "👤 Cliente: " . htmlspecialchars($sale->buyer_name, ENT_QUOTES);
        }
        if ($sale->buyer_phone) {
            $lines[] = "📱 Teléfono: " . htmlspecialchars($sale->buyer_phone, ENT_QUOTES);
        }
        $lines[] = '';
        $lines[] = "📦 <b>Productos:</b>";

        foreach ($sale->items as $item) {
            $name = htmlspecialchars((string) ($item->product?->name ?? 'Producto'), ENT_QUOTES);
            $qty = (int) $item->quantity;
            $lineTotal = number_format(($item->subtotal ?? ($item->unit_price * $qty)) / 100, 2);
            $lines[] = "  • {$qty}× {$name} — {$currency} {$lineTotal}";
        }

        $lines[] = '';
        $total = number_format($sale->total / 100, 2);
        $lines[] = "💰 <b>Total: {$currency} {$total}</b>";
        $lines[] = '';
        $lines[] = "Estado: <b>Pendiente</b> (esperando confirmación cliente)";

        $message = implode("\n", $lines);

        try {
            $this->telegram->sendMessage($chatId, $message, 'HTML');
        } catch (\Throwable $e) {
            // No bloquear flujo de reserva si Telegram falla.
            Log::warning('Failed to send Telegram notification for web reservation', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
