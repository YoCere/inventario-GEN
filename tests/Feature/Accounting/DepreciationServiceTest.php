<?php

namespace Tests\Feature\Accounting;

use App\Enums\FixedAssetStatus;
use App\Models\AssetCategory;
use App\Models\DepreciationRun;
use App\Models\FixedAsset;
use App\Models\User;
use App\Services\Accounting\DepreciationService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\AssetCategorySeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepreciationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class, AssetCategorySeeder::class]);
        User::factory()->admin()->create();
    }

    private function vehicle(int $cost = 7000000, int $residual = 0, int $life = 60, string $start = '2026-01-01', string $code = 'VEH-001'): FixedAsset
    {
        $cat = AssetCategory::where('name', 'Vehículos')->first();
        return FixedAsset::create([
            'asset_category_id' => $cat->id, 'code' => $code, 'name' => 'Camioneta',
            'acquisition_date' => '2026-01-01', 'acquisition_cost' => $cost, 'residual_value' => $residual,
            'useful_life_months' => $life, 'depreciation_start_date' => $start,
            'status' => 'active', 'accumulated_depreciation' => 0,
        ]);
    }

    public function test_monthly_amount_straight_line(): void
    {
        $this->assertEquals(116667, app(DepreciationService::class)->monthlyAmount($this->vehicle()));
    }

    public function test_run_posts_balanced_entry_and_records_run(): void
    {
        $asset = $this->vehicle();
        app(DepreciationService::class)->runForMonth('2026-01');

        $run = DepreciationRun::where('fixed_asset_id', $asset->id)->where('year_month', '2026-01')->first();
        $this->assertNotNull($run);
        $this->assertEquals(116667, $run->amount);

        $entry = \App\Models\JournalEntry::with('lines')->find($run->journal_entry_id);
        $this->assertEquals($entry->lines->sum('debit_amount'), $entry->lines->sum('credit_amount'));
        $debitAcc = \App\Models\ChartOfAccount::where('code', '6.4')->first();
        $creditAcc = \App\Models\ChartOfAccount::where('code', '1.2.02')->first();
        $this->assertEquals(116667, $entry->lines->where('chart_of_account_id', $debitAcc->id)->sum('debit_amount'));
        $this->assertEquals(116667, $entry->lines->where('chart_of_account_id', $creditAcc->id)->sum('credit_amount'));
        $this->assertEquals(116667, $asset->fresh()->accumulated_depreciation);
    }

    public function test_run_is_idempotent_per_month(): void
    {
        $asset = $this->vehicle();
        app(DepreciationService::class)->runForMonth('2026-01');
        app(DepreciationService::class)->runForMonth('2026-01');
        $this->assertEquals(1, DepreciationRun::where('fixed_asset_id', $asset->id)->where('year_month', '2026-01')->count());
        $this->assertEquals(116667, $asset->fresh()->accumulated_depreciation);
    }

    public function test_full_life_accumulates_exact_base_and_marks_fully_depreciated(): void
    {
        $asset = $this->vehicle();
        $svc = app(DepreciationService::class);
        $period = new \DateTime('2026-01-01');
        for ($i = 0; $i < 60; $i++) { $svc->runForMonth($period->format('Y-m')); $period->modify('+1 month'); }

        $asset->refresh();
        $this->assertEquals(7000000, $asset->accumulated_depreciation);
        $this->assertEquals(FixedAssetStatus::FullyDepreciated, $asset->status);
        $this->assertEquals(0, $asset->bookValue());

        $first12 = DepreciationRun::where('fixed_asset_id', $asset->id)->orderBy('year_month')->limit(12)->sum('amount');
        $this->assertEqualsWithDelta(1400000, $first12, 60);
    }

    public function test_does_not_depreciate_before_start_date(): void
    {
        $asset = $this->vehicle(start: '2026-04-01', code: 'VEH-002');
        app(DepreciationService::class)->runForMonth('2026-03');
        $this->assertEquals(0, DepreciationRun::where('fixed_asset_id', $asset->id)->count());
        app(DepreciationService::class)->runForMonth('2026-04');
        $this->assertEquals(1, DepreciationRun::where('fixed_asset_id', $asset->id)->count());
    }
}
