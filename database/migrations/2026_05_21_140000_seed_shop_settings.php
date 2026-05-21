<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Settings del módulo Tienda en línea. Todos opcionales — solo se usan
     * cuando shop_enabled='1'. Modelo de addon premium: feature flag OFF
     * por defecto para deploys nuevos.
     */
    private const SHOP_SETTINGS = [
        'shop_enabled' => '0',
        'shop_whatsapp_number' => '',
        'shop_business_name' => '',
        'shop_currency_symbol' => 'Bs.',
        'shop_welcome_message' => '',
        'shop_show_out_of_stock' => '0',
    ];

    public function up(): void
    {
        foreach (self::SHOP_SETTINGS as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    public function down(): void
    {
        Setting::whereIn('key', array_keys(self::SHOP_SETTINGS))->delete();
    }
};
