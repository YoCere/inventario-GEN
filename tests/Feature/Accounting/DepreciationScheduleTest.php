<?php

namespace Tests\Feature\Accounting;

use App\Livewire\FixedAssets\DepreciationSchedule;
use App\Models\AssetCategory;
use App\Models\FixedAsset;
use App\Models\User;
use App\Services\Accounting\DepreciationService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\AssetCategorySeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DepreciationScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_renders_runs(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class, AssetCategorySeeder::class]);
        $admin = User::factory()->admin()->create();

        $cat = AssetCategory::where('name', 'Vehículos')->first();

        $asset = FixedAsset::create([
            'asset_category_id'       => $cat->id,
            'code'                    => 'VEH-S',
            'name'                    => 'Camioneta',
            'acquisition_date'        => '2026-01-01',
            'acquisition_cost'        => 7000000,
            'residual_value'          => 0,
            'useful_life_months'      => 60,
            'depreciation_start_date' => '2026-01-01',
            'status'                  => 'active',
            'accumulated_depreciation' => 0,
        ]);

        app(DepreciationService::class)->runForMonth('2026-01');

        Livewire::actingAs($admin)
            ->test(DepreciationSchedule::class, ['assetId' => $asset->id])
            ->assertOk()
            ->assertSee('2026-01');
    }
}
