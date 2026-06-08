<?php

namespace Tests\Feature\Accounting;

use App\Models\Budget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_budget_with_lines(): void
    {
        $b = Budget::create(['name' => 'Plan 2026', 'base_from' => '2025-01-01', 'base_to' => '2025-12-31', 'years' => 5, 'growth_pct' => 3]);
        $b->lines()->create(['chart_of_account_code' => '4.1', 'name' => 'Ventas', 'line_type' => 'income', 'base_amount' => 10000000]);
        $b->lines()->create(['chart_of_account_code' => '5.1', 'name' => 'Costo', 'line_type' => 'cost', 'base_amount' => 6000000]);

        $this->assertEquals(2, $b->lines()->count());
        $this->assertEquals(5, $b->fresh()->years);
        $this->assertTrue($b->is_active);
    }
}
