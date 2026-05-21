<?php

namespace Tests\Feature\Shop;

use App\Models\Setting;
use App\Shop\Providers\ShopServiceProvider;
use App\Shop\Services\ShopFeatureFlag;
use Illuminate\Support\Facades\Route;

/**
 * Trait helper para tests del Shop module. ShopServiceProvider::boot() ya
 * corrió cuando la app arrancó (con shop_enabled='0' por seeder), así que
 * las rutas /tienda/* no están registradas. Tests que necesitan probar el
 * flujo público invocan enableShop() en setUp para activar flag + cargar
 * rutas manualmente.
 */
trait EnablesShop
{
    protected function enableShop(): void
    {
        Setting::set('shop_enabled', '1');
        app(ShopFeatureFlag::class)->invalidate();

        if (! Route::has('shop.index')) {
            // El provider ya está registrado en la app — obtener la instancia existente
            // (que tiene $app inyectado). Crear una nueva con `app(::class)` falla porque
            // ServiceProvider::__construct requiere Application como primer parámetro.
            $provider = $this->app->getProvider(ShopServiceProvider::class);
            $provider->loadShopRoutes();

            // Refrescar lookups por nombre/acción: loadRoutesFrom mid-test agrega rutas
            // al collection pero los caches internos de búsqueda (route('shop.xxx'))
            // se construyeron antes y quedaron sin las nuevas entradas.
            $routes = $this->app['router']->getRoutes();
            $routes->refreshNameLookups();
            $routes->refreshActionLookups();
        }
    }
}
