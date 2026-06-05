<?php

namespace Tests\Feature\Accounting;

use App\Models\AssetCategory;
use App\Models\DepreciationRun;
use App\Models\FixedAsset;
use App\Models\User;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\AssetCategorySeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunDepreciationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_posts_depreciation(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class, AssetCategorySeeder::class]);
        User::factory()->admin()->create();
        $cat = AssetCategory::where('name', 'Vehículos')->first();
        $asset = FixedAsset::create([
            'asset_category_id' => $cat->id, 'code' => 'VEH-001', 'name' => 'Camioneta',
            'acquisition_date' => '2026-01-01', 'acquisition_cost' => 7000000, 'residual_value' => 0,
            'useful_life_months' => 60, 'depreciation_start_date' => '2026-01-01',
            'status' => 'active', 'accumulated_depreciation' => 0,
        ]);

        $this->artisan('depreciation:run', ['--month' => '2026-01'])->assertExitCode(0);

        $this->assertEquals(1, DepreciationRun::where('fixed_asset_id', $asset->id)->count());
    }
}
