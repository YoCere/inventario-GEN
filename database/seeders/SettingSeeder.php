<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Setting::set('store_name', 'Importadora El Cóndor');
        Setting::set('store_address', 'Av. Antofagasta N° 145, Oruro, Bolivia');
        Setting::set('store_phone', '72345678');
        Setting::set('opening_balance_date', now()->startOfYear()->toDateString());
        Setting::set('opening_balance_amount', '50000');
        Setting::set('discount_rate_annual', '12');
        Setting::set('currency_symbol', 'Bs');
        Setting::set('currency_position', 'left');
        Setting::set('currency_fraction_digits', '2');
        Setting::set('currency_thousand_separator', '.');
        Setting::set('currency_decimal_separator', ',');
    }
}
