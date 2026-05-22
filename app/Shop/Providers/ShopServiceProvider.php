<?php

namespace App\Shop\Providers;

use App\Models\Product;
use App\Shop\Events\WebReservationCreated;
use App\Shop\Listeners\NotifyAdminViaTelegram;
use App\Shop\Observers\ProductObserver;
use App\Shop\Services\ShopFeatureFlag;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ShopServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ShopFeatureFlag::class);
    }

    public function boot(ShopFeatureFlag $flag): void
    {
        // Comandos artisan se registran SIEMPRE (no gated por flag) — admin puede
        // querer regenerar imágenes antes de activar la tienda + el job de
        // auto-cancelación debe poder correrse aunque el flag se haya apagado
        // temporalmente para limpiar reservas viejas.
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Shop\Console\Commands\RegenerateImagesCommand::class,
                \App\Shop\Console\Commands\CancelExpiredReservationsCommand::class,
                \App\Shop\Console\Commands\PublishAllProductsCommand::class,
                \App\Shop\Console\Commands\UnpublishAllProductsCommand::class,
            ]);
        }

        if (! $flag->enabled()) {
            return;
        }

        $this->loadShopRoutes();
        $this->loadViewsFrom(resource_path('views/shop'), 'shop');

        Event::listen(WebReservationCreated::class, NotifyAdminViaTelegram::class);

        // Observer invalida caches de catálogo + precio + categorías al editar
        // productos desde admin. Sin esto, los filtros del sidebar quedan
        // rancios hasta el TTL natural (5 min).
        Product::observe(ProductObserver::class);
    }

    /**
     * Carga las definiciones de rutas del módulo. Público para que los tests
     * puedan invocarlo después de activar el flag (el provider ya bootea
     * antes de que setUp() habilite shop_enabled).
     */
    public function loadShopRoutes(): void
    {
        $this->loadRoutesFrom(base_path('routes/shop.php'));
        $this->loadRoutesFrom(base_path('routes/shop-admin.php'));
    }
}
