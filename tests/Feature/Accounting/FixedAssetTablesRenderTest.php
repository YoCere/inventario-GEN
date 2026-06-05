<?php

namespace Tests\Feature\Accounting;

use App\Livewire\AssetCategories\AssetCategoryTable;
use App\Livewire\FixedAssets\FixedAssetTable;
use App\Models\AssetCategory;
use App\Models\FixedAsset;
use App\Models\User;
use Database\Seeders\AssetCategorySeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Renderiza los componentes PowerGrid completos (columns()+fields()).
 * Los smoke tests de Form no ejercían la tabla; un Column::html() inexistente
 * pasaba CI pero rompía la página con HTTP 500.
 */
class FixedAssetTablesRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_asset_category_table_renders(): void
    {
        $this->seed([ChartOfAccountSeeder::class, AssetCategorySeeder::class]);
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(AssetCategoryTable::class)->assertOk();
    }

    public function test_fixed_asset_table_renders(): void
    {
        $this->seed([ChartOfAccountSeeder::class, AssetCategorySeeder::class]);
        $admin = User::factory()->admin()->create();
        $cat = AssetCategory::where('name', 'Vehículos')->first();
        FixedAsset::create([
            'asset_category_id' => $cat->id, 'code' => 'VEH-R', 'name' => 'Camioneta',
            'acquisition_date' => '2026-01-01', 'acquisition_cost' => 7000000, 'residual_value' => 0,
            'useful_life_months' => 60, 'depreciation_start_date' => '2026-02-01',
            'status' => 'active', 'accumulated_depreciation' => 0,
        ]);

        Livewire::actingAs($admin)->test(FixedAssetTable::class)->assertOk();
    }
}
