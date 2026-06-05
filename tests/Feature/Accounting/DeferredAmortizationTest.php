<?php

namespace Tests\Feature\Accounting;

use App\Models\AssetCategory;
use App\Models\ChartOfAccount;
use App\Models\DepreciationRun;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\DepreciationService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\AssetCategorySeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeferredAmortizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_deferred_asset_amortizes_to_amortization_accounts(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class, AssetCategorySeeder::class]);
        User::factory()->admin()->create();
        $cat = AssetCategory::where('is_deferred', true)->first();

        $asset = FixedAsset::create([
            'asset_category_id' => $cat->id, 'code' => 'DIF-001', 'name' => 'Gastos de organización',
            'acquisition_date' => '2026-01-01', 'acquisition_cost' => 600000, 'residual_value' => 0,
            'useful_life_months' => 60, 'depreciation_start_date' => '2026-01-01',
            'status' => 'active', 'accumulated_depreciation' => 0,
        ]);

        app(DepreciationService::class)->runForMonth('2026-01');

        $run = DepreciationRun::where('fixed_asset_id', $asset->id)->first();
        $this->assertNotNull($run);
        $entry = JournalEntry::with('lines')->find($run->journal_entry_id);
        $gasto = ChartOfAccount::where('code', '6.5')->first();   // Gasto Amortización
        $acum = ChartOfAccount::where('code', '1.2.04')->first(); // Amortización Acumulada
        $this->assertEquals(10000, $entry->lines->where('chart_of_account_id', $gasto->id)->sum('debit_amount')); // 600.000/60
        $this->assertEquals(10000, $entry->lines->where('chart_of_account_id', $acum->id)->sum('credit_amount'));
        // glosa dice "Amortización" no "Depreciación"
        $this->assertStringContainsString('Amortización', $entry->description);
    }
}
