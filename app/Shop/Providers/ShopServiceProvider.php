<?php

namespace App\Shop\Providers;

use App\Shop\Events\WebReservationCreated;
use App\Shop\Listeners\NotifyAdminViaTelegram;
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
        // querer regenerar imágenes antes de activar la tienda.
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Shop\Console\Commands\RegenerateImagesCommand::class,
            ]);
        }

        if (! $flag->enabled()) {
            return;
        }

        $this->loadRoutesFrom(base_path('routes/shop.php'));
        $this->loadRoutesFrom(base_path('routes/shop-admin.php'));
        $this->loadViewsFrom(resource_path('views/shop'), 'shop');

        Event::listen(WebReservationCreated::class, NotifyAdminViaTelegram::class);
    }
}
