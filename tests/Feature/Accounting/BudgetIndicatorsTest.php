<?php

namespace Tests\Feature\Accounting;

use App\Models\AssetCategory;
use App\Models\Budget;
use App\Models\FixedAsset;
use App\Services\Accounting\BudgetProjectionService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\AssetCategorySeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetIndicatorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_indicators_over_projected_flow(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class, AssetCategorySeeder::class]);

        $cat = AssetCategory::where('name', 'Vehículos')->first();
        FixedAsset::create([
            'asset_category_id' => $cat->id, 'code' => 'INV-1', 'name' => 'Inversión',
            'acquisition_date' => '2025-01-01', 'acquisition_cost' => 10000000, 'residual_value' => 0,
            'useful_life_months' => 60, 'depreciation_start_date' => '2025-02-01',
            'status' => 'active', 'accumulated_depreciation' => 0,
        ]);

        $b = Budget::create(['name' => 'P', 'base_from' => '2025-01-01', 'base_to' => '2025-12-31', 'years' => 5, 'growth_pct' => 3, 'discount_rate_pct' => 12, 'iue_rate_pct' => 25]);
        $b->lines()->create(['chart_of_account_code' => '4.1', 'name' => 'Ventas', 'line_type' => 'income', 'base_amount' => 10000000]);
        $b->lines()->create(['chart_of_account_code' => '5.1', 'name' => 'Costo', 'line_type' => 'cost', 'base_amount' => 6000000]);

        $ind = app(BudgetProjectionService::class)->indicators($b->fresh('lines'));

        $this->assertEquals(10000000, $ind['investment_base']);
        $this->assertNotNull($ind['van']);
        $this->assertNotNull($ind['tir_annual_pct']);
        $this->assertGreaterThan(0, $ind['tir_annual_pct']);
        $this->assertNotNull($ind['payback_years']);
        $this->assertGreaterThan(1.0, $ind['benefit_cost_ratio']);
    }
}
