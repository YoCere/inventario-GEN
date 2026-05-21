<?php

namespace App\Shop\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class ShopFeatureFlag
{
    private const CACHE_KEY = 'shop.enabled';
    private const CACHE_TTL_SECONDS = 60;

    public function enabled(): bool
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): bool {
                return Setting::get('shop_enabled') === '1';
            });
        } catch (\Throwable $e) {
            // DB no migrada o cache backend frío al boot. Tratar como deshabilitado.
            return false;
        }
    }

    public function invalidate(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
