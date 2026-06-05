<?php

namespace Tests\Feature\Accounting;

use App\Enums\FixedAssetStatus;
use App\Models\AssetCategory;
use App\Models\ChartOfAccount;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\DepreciationService;
use App\Services\Accounting\FixedAssetService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\AssetCategorySeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixedAssetServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class, AssetCategorySeeder::class]);
        $this->userId = User::factory()->admin()->create()->id;
        ChartOfAccount::firstOrCreate(['code' => '1.1.1.01'],
            ['name' => 'Caja', 'account_type' => 'asset', 'normal_balance' => 'debit',
             'allows_posting' => true, 'is_active' => true, 'level' => 4]);
    }

    private function data(array $over = []): array
    {
        $cat = AssetCategory::where('name', 'Vehículos')->first();
        return array_merge([
            'asset_category_id' => $cat->id, 'code' => 'VEH-001', 'name' => 'Camioneta',
            'acquisition_date' => '2026-01-01', 'acquisition_cost' => 7000000, 'residual_value' => 0,
            'useful_life_months' => 60, 'depreciation_start_date' => '2026-02-01',
        ], $over);
    }

    public function test_register_new_posts_purchase_entry(): void
    {
        $asset = app(FixedAssetService::class)->registerNew($this->data(), '1.1.1.01', $this->userId);

        $this->assertFalse($asset->is_opening);
        $this->assertNotNull($asset->acquisition_entry_id);
        $entry = JournalEntry::with('lines')->find($asset->acquisition_entry_id);
        $ppe = ChartOfAccount::where('code', '1.2.01')->first();
        $caja = ChartOfAccount::where('code', '1.1.1.01')->first();
        $this->assertEquals(7000000, $entry->lines->where('chart_of_account_id', $ppe->id)->sum('debit_amount'));
        $this->assertEquals(7000000, $entry->lines->where('chart_of_account_id', $caja->id)->sum('credit_amount'));
    }

    public function test_register_opening_no_pl_entry(): void
    {
        $asset = app(FixedAssetService::class)->registerOpening($this->data(['code' => 'VEH-OPEN']), 2000000, $this->userId);
        $this->assertTrue($asset->is_opening);
        $this->assertNull($asset->acquisition_entry_id);
        $this->assertEquals(2000000, $asset->accumulated_depreciation);
    }

    public function test_dispose_with_gain(): void
    {
        $asset = app(FixedAssetService::class)->registerNew($this->data(['code' => 'VEH-D']), '1.1.1.01', $this->userId);
        $svc = app(DepreciationService::class);
        $p = new \DateTime('2026-02-01');
        for ($i = 0; $i < 12; $i++) { $svc->runForMonth($p->format('Y-m')); $p->modify('+1 month'); }
        $asset->refresh();
        $book = $asset->bookValue();

        $disposed = app(FixedAssetService::class)->dispose($asset, '2027-02-28', $book + 50000, '1.1.1.01', '4.2', $this->userId);

        $this->assertEquals(FixedAssetStatus::Disposed, $disposed->fresh()->status);
        $entry = JournalEntry::with('lines')->find($disposed->disposal_entry_id);
        $this->assertEquals($entry->lines->sum('debit_amount'), $entry->lines->sum('credit_amount'));
        $gain = ChartOfAccount::where('code', '4.2')->first();
        $this->assertEquals(50000, $entry->lines->where('chart_of_account_id', $gain->id)->sum('credit_amount'));
    }

    public function test_dispose_with_loss(): void
    {
        $asset = app(FixedAssetService::class)->registerNew($this->data(['code' => 'VEH-L']), '1.1.1.01', $this->userId);
        $asset->refresh();
        $book = $asset->bookValue();

        $disposed = app(FixedAssetService::class)->dispose($asset, '2026-03-31', $book - 100000, '1.1.1.01', '6.6', $this->userId);

        $entry = JournalEntry::with('lines')->find($disposed->disposal_entry_id);
        $this->assertEquals($entry->lines->sum('debit_amount'), $entry->lines->sum('credit_amount'));
        $loss = ChartOfAccount::where('code', '6.6')->first();
        $this->assertEquals(100000, $entry->lines->where('chart_of_account_id', $loss->id)->sum('debit_amount'));
    }
}
