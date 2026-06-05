<?php

namespace Tests\Feature\Accounting;

use App\Models\AssetCategory;
use App\Models\FixedAsset;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\AssetCategorySeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestmentBaseConsolidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_investment_base_uses_real_assets_when_present(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class, AssetCategorySeeder::class]);
        $cat = AssetCategory::where('name', 'Vehículos')->first();
        FixedAsset::create([
            'asset_category_id' => $cat->id, 'code' => 'VEH-1', 'name' => 'Camioneta',
            'acquisition_date' => '2026-01-01', 'acquisition_cost' => 7000000, 'residual_value' => 0,
            'useful_life_months' => 60, 'depreciation_start_date' => '2026-02-01',
            'status' => 'active', 'accumulated_depreciation' => 0,
        ]);

        $data = app(\App\Services\FinancialStatementService::class)->build('2026-01-01', '2026-01-31', false);
        // investment_base is exposed directly in the indicadores_inversion array
        $base = $data['indicadores_inversion']['investment_base'] ?? null;
        $this->assertNotNull($base, 'buildInvestmentIndicators debe exponer investment_base');
        $this->assertGreaterThanOrEqual(7000000, (int) $base);
    }
}
