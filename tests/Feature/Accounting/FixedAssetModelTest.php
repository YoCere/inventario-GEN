<?php

namespace Tests\Feature\Accounting;

use App\Enums\FixedAssetStatus;
use App\Models\AssetCategory;
use App\Models\FixedAsset;
use Database\Seeders\AssetCategorySeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixedAssetModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_book_value_and_depreciable_base(): void
    {
        $this->seed([ChartOfAccountSeeder::class, AssetCategorySeeder::class]);
        $cat = AssetCategory::where('name', 'Vehículos')->first();

        $asset = FixedAsset::create([
            'asset_category_id' => $cat->id, 'code' => 'VEH-001', 'name' => 'Camioneta',
            'acquisition_date' => '2026-01-01', 'acquisition_cost' => 7000000, 'residual_value' => 0,
            'useful_life_months' => 60, 'depreciation_start_date' => '2026-02-01',
            'accumulated_depreciation' => 1000000,
        ]);

        $this->assertEquals(6000000, $asset->bookValue());
        $this->assertEquals(7000000, $asset->depreciableBase());
        $this->assertEquals(FixedAssetStatus::Active, $asset->status);
    }
}
