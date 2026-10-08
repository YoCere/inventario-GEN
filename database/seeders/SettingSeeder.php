<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Ajustes para DESARROLLO y demos: los de ley salen de BaseSettingsSeeder y acá
 * se agrega la identidad de un negocio de ejemplo.
 *
 * Una instancia de cliente real NO usa este seeder: usa `php artisan instalar:cliente`.
 */
class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(BaseSettingsSeeder::class);

        // Negocio de ejemplo (solo demo).
        Setting::set('store_name', 'Importadora El Cóndor');
        Setting::set('store_nit', '');
        Setting::set('store_address', 'Av. Antofagasta N° 145, Oruro, Bolivia');
        Setting::set('store_phone', '72345678');
        Setting::set('business_timezone', 'America/La_Paz');
        Setting::set('opening_balance_date', now()->startOfYear()->toDateString());
        Setting::set('opening_balance_amount', '50000');
        Setting::set('tax_include_iva', '1');
        Setting::set('tax_include_it', '1');
    }
}
