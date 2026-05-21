<?php

namespace App\Shop\Providers;

use App\Shop\Services\ShopFeatureFlag;
use Illuminate\Support\ServiceProvider;

class ShopServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ShopFeatureFlag::class);
    }

    public function boot(ShopFeatureFlag $flag): void
    {
        if (! $flag->enabled()) {
            return;
        }

        $this->loadRoutesFrom(base_path('routes/shop.php'));
        $this->loadRoutesFrom(base_path('routes/shop-admin.php'));
        $this->loadViewsFrom(resource_path('views/shop'), 'shop');
    }
}
