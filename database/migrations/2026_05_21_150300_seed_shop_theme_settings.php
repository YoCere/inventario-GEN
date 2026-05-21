<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Settings de personalización visual de la tienda. Permiten al cliente
     * cambiar logo + paleta sin tocar código.
     *
     * Defaults: paleta Tailwind moderna (blue-600 / slate-500 / amber-500)
     * y logo vacío (el catálogo cae a mostrar shop_business_name como texto).
     */
    private const THEME_SETTINGS = [
        'shop_logo_path' => '',
        'shop_primary_color' => '#2563EB',
        'shop_secondary_color' => '#64748B',
        'shop_accent_color' => '#F59E0B',
        'shop_text_on_primary' => '#FFFFFF',
    ];

    public function up(): void
    {
        foreach (self::THEME_SETTINGS as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    public function down(): void
    {
        Setting::whereIn('key', array_keys(self::THEME_SETTINGS))->delete();
    }
};
