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
}
