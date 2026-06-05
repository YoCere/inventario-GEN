<?php

namespace Database\Seeders;

use App\Models\AssetCategory;
use Illuminate\Database\Seeder;

class AssetCategorySeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['Edificios', 480, 2.50, false],
            ['Maquinaria y equipos', 96, 12.50, false],
            ['Muebles y enseres', 120, 10.00, false],
            ['Herramientas', 60, 20.00, false],
            ['Equipos de computación', 48, 25.00, false],
            ['Vehículos', 60, 20.00, false],
            ['Activo diferido', 60, 20.00, true],
        ];

        foreach ($rows as [$name, $life, $rate, $deferred]) {
            $accumulated = $deferred ? '1.2.04' : '1.2.02';
            $ppe = $deferred ? '1.2.03' : '1.2.01';
            $expense = $deferred ? '6.5' : '6.4';

            AssetCategory::firstOrCreate(
                ['name' => $name],
                [
                    'useful_life_months' => $life,
                    'annual_rate_pct' => $rate,
                    'is_deferred' => $deferred,
                    'ppe_account_code' => $ppe,
                    'accumulated_account_code' => $accumulated,
                    'expense_account_code' => $expense,
                    'is_active' => true,
                ]
            );
        }
    }
}
