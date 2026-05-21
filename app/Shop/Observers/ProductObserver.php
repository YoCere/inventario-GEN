<?php

namespace App\Shop\Observers;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;

/**
 * Invalida caches del Shop module cuando cambia un producto.
 *
 * Caches afectados:
 *   - shop.categories_with_public_products (sidebar filtro)
 *   - shop.price_range (slider precio)
 *   - shop.search.* (resultados buscador) — wildcard via flush por tag
 *     no soportado en file/database driver, así que hacemos best-effort:
 *     limpiamos el rango y categorías; las búsquedas individuales caen
 *     por TTL 60s naturalmente.
 */
class ProductObserver
{
    public function saved(Product $product): void
    {
        $this->invalidateShopCaches();
    }

    public function deleted(Product $product): void
    {
        $this->invalidateShopCaches();
    }

    private function invalidateShopCaches(): void
    {
        Cache::forget('shop.categories_with_public_products');
        Cache::forget('shop.price_range');
    }
}
