<?php

namespace Tests\Feature\Accounting;

use App\Livewire\FixedAssets\FixedAssetForm;
use App\Models\AssetCategory;
use App\Models\ChartOfAccount;
use App\Models\FixedAsset;
use App\Models\User;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\AssetCategorySeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FixedAssetPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_registers_new_asset_via_form(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class, AssetCategorySeeder::class]);
        $admin = User::factory()->admin()->create();

        ChartOfAccount::firstOrCreate(
            ['code' => '1.1.1.01'],
            ['name' => 'Caja', 'account_type' => 'asset', 'normal_balance' => 'debit', 'allows_posting' => true, 'is_active' => true, 'level' => 4]
        );

        $cat = AssetCategory::where('name', 'Vehículos')->first();

        Livewire::actingAs($admin)->test(FixedAssetForm::class)
            ->set('mode', 'new')
            ->set('asset_category_id', $cat->id)
            ->set('code', 'VEH-100')
            ->set('name', 'Camioneta nueva')
            ->set('acquisition_date', '2026-01-01')
            ->set('acquisition_cost', 70000)
            ->set('residual_value', 0)
            ->set('useful_life_months', 60)
            ->set('depreciation_start_date', '2026-02-01')
            ->set('funding_account_code', '1.1.1.01')
            ->call('save')
            ->assertHasNoErrors();

        $asset = FixedAsset::where('code', 'VEH-100')->first();
        $this->assertNotNull($asset);
        $this->assertEquals(7000000, $asset->acquisition_cost);
        $this->assertNotNull($asset->acquisition_entry_id);
    }
}
