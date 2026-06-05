<?php

namespace Tests\Feature\Accounting;

use App\Livewire\AssetCategories\AssetCategoryForm;
use App\Models\AssetCategory;
use App\Models\User;
use Database\Seeders\AssetCategorySeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AssetCategoryCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_category(): void
    {
        $this->seed([ChartOfAccountSeeder::class, AssetCategorySeeder::class]);
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(AssetCategoryForm::class)
            ->set('name', 'Inmuebles especiales')
            ->set('useful_life_months', 240)
            ->set('annual_rate_pct', 5)
            ->set('is_deferred', false)
            ->set('ppe_account_code', '1.2.01')
            ->set('accumulated_account_code', '1.2.02')
            ->set('expense_account_code', '6.4')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNotNull(AssetCategory::where('name', 'Inmuebles especiales')->first());
    }
}
