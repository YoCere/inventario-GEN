<?php

namespace App\Shop;

use App\Models\Setting;

/**
 * Datos de marca de la tienda en línea. Salen de "Mi negocio" y "Moneda" para no
 * pedir lo mismo dos veces; la tienda solo puede tener un nombre propio opcional.
 */
final class ShopSettings
{
    public static function businessName(): string
    {
        return Setting::get('shop_business_name')
            ?: Setting::get('store_name')
            ?: (string) config('app.name');
    }

    public static function currencySymbol(): string
    {
        return Setting::get('currency_symbol') ?: 'Bs';
    }

    /**
     * ¿La tienda pública muestra precios?
     *
     * Apagado = catálogo de vitrina: el visitante ve el producto y pide por
     * WhatsApp, pero no el precio (para que la competencia no copie la lista).
     * Cuando está apagado NO alcanza con esconder el número: también se quitan el
     * filtro y el orden por precio, el precio del buscador, los totales del
     * carrito y los montos del mensaje de WhatsApp, porque con cualquiera de esos
     * se deduce el precio igual. El dueño sí ve los montos en Reservas web.
     */
    public static function showPrices(): bool
    {
        return Setting::get('shop_show_prices', '1') !== '0';
    }
}
