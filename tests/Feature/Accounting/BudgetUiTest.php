<?php

namespace Tests\Feature\Accounting;

use App\Livewire\Budgets\BudgetForm;
use App\Livewire\Budgets\BudgetTable;
use App\Models\Budget;
use App\Models\User;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BudgetUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
    }

    public function test_table_renders(): void
    {
        $admin = User::factory()->admin()->create();
        Livewire::actingAs($admin)->test(BudgetTable::class)->assertOk();
    }

    public function test_table_renders_with_a_budget_row(): void
    {
        // Con una fila (fechas) la tabla PowerGrid ejercita el formateo de fecha
        // que rompía el render del index con HTTP 500.
        $admin = User::factory()->admin()->create();
        Budget::create(['name' => 'Plan', 'base_from' => '2025-01-01', 'base_to' => '2025-12-31', 'years' => 5, 'growth_pct' => 3]);
        Livewire::actingAs($admin)->test(BudgetTable::class)->assertOk()->assertSee('Plan');
    }

    public function test_detail_renders(): void
    {
        // Render completo de BudgetDetail (proyección/indicadores/vs real); cazaría
        // la colisión de prop $budget int vs el modelo en la vista.
        $admin = User::factory()->admin()->create();
        $b = Budget::create(['name' => 'Plan', 'base_from' => '2025-01-01', 'base_to' => '2025-12-31', 'years' => 5, 'growth_pct' => 3]);
        $b->lines()->create(['chart_of_account_code' => '4.1', 'name' => 'Ventas', 'line_type' => 'income', 'base_amount' => 10000000]);
        Livewire::actingAs($admin)->test(\App\Livewire\Budgets\BudgetDetail::class, ['budget' => $b->id])
            ->assertOk()->assertSee('Plan');
    }

    public function test_admin_creates_budget(): void
    {
        $admin = User::factory()->admin()->create();
        Livewire::actingAs($admin)->test(BudgetForm::class)
            ->set('name', 'Plan 2026')->set('base_from', '2025-01-01')->set('base_to', '2025-12-31')
            ->set('years', 5)->set('growth_pct', 3)->set('discount_rate_pct', 12)->set('iue_rate_pct', 25)
            ->call('save');
        $this->assertNotNull(Budget::where('name', 'Plan 2026')->first());
    }

    public function test_non_admin_cannot_create_budget(): void
    {
        $user = User::factory()->create();
        Livewire::actingAs($user)->test(BudgetForm::class)
            ->set('name', 'X')->set('base_from', '2025-01-01')->set('base_to', '2025-12-31')
            ->set('years', 5)->set('growth_pct', 3)->set('discount_rate_pct', 12)->set('iue_rate_pct', 25)
            ->call('save')->assertStatus(403);
        $this->assertEquals(0, Budget::count());
    }
}
