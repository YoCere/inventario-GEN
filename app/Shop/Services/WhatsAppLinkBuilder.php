<?php

namespace App\Shop\Services;

use App\Models\Sale;
use App\Models\Setting;

class WhatsAppLinkBuilder
{
    /**
     * Construye URL wa.me con mensaje pre-armado para que el cliente envíe
     * al admin desde su WhatsApp. Formato consistente, parseable a ojo
     * por el admin que recibe la reserva.
     *
     * Si shop_whatsapp_number está vacío, retorna URL wa.me sin destinatario
     * (cliente debe elegir contacto) — escenario raro pero defensivo.
     */
    public function build(Sale $sale): string
    {
        $message = $this->composeMessage($sale);
        $phone = $this->sanitizePhone(Setting::get('shop_whatsapp_number', ''));

        $base = $phone ? "https://wa.me/{$phone}" : 'https://wa.me/';
        return $base . '?text=' . rawurlencode($message);
    }

    public function composeMessage(Sale $sale): string
    {
        $sale->loadMissing(['items.product']);

        $currency = Setting::get('shop_currency_symbol', 'Bs.');
        $businessName = Setting::get('shop_business_name') ?: config('app.name');

        $lines = [];
        $lines[] = "🛒 *Nueva reserva en {$businessName}*";
        $lines[] = "Pedido: *{$sale->invoice_number}*";
        $lines[] = '';

        if ($sale->buyer_name) {
            $lines[] = "👤 *Cliente:* {$sale->buyer_name}";
        }
        if ($sale->buyer_phone) {
            $lines[] = "📱 *Teléfono:* {$sale->buyer_phone}";
        }
        $lines[] = '';
        $lines[] = '📦 *Productos:*';

        foreach ($sale->items as $item) {
            $name = $item->product?->name ?? 'Producto';
            $qty = (int) $item->quantity;
            $lineTotal = number_format(($item->subtotal ?? ($item->unit_price * $qty)) / 100, 2);
            $lines[] = "  • {$qty}× {$name} — {$currency} {$lineTotal}";
        }

        $lines[] = '';
        $total = number_format($sale->total / 100, 2);
        $lines[] = "💰 *Total: {$currency} {$total}*";
        $lines[] = '';
        $lines[] = 'Reservado en línea — pendiente de pago.';
        $lines[] = 'Por favor confírmame disponibilidad y forma de pago. ¡Gracias!';

        return implode("\n", $lines);
    }

    /**
     * Quita +, espacios, guiones y paréntesis. wa.me requiere solo dígitos.
     */
    private function sanitizePhone(string $raw): string
    {
        return preg_replace('/\D+/', '', $raw) ?? '';
    }
}
