<?php

namespace Tests\Feature\Accounting;

use App\Models\AssetCategory;
use Database\Seeders\AssetCategorySeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_seven_categories(): void
    {
        $this->seed([ChartOfAccountSeeder::class, AssetCategorySeeder::class]);

        $this->assertEquals(7, AssetCategory::count());
        $veh = AssetCategory::where('name', 'Vehículos')->first();
        $this->assertEquals(60, $veh->useful_life_months);
        $this->assertEquals('1.2.02', $veh->accumulated_account_code);
        $this->assertTrue(AssetCategory::where('name', 'Activo diferido')->first()->is_deferred);
    }
}
