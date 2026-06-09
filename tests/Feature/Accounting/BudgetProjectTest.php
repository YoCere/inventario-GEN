<?php

namespace Tests\Feature\Accounting;

use App\Models\Budget;
use App\Services\Accounting\BudgetProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_projection_compound_growth(): void
    {
        $b = Budget::create(['name' => 'P', 'base_from' => '2025-01-01', 'base_to' => '2025-12-31', 'years' => 5, 'growth_pct' => 3, 'iue_rate_pct' => 25]);
        $b->lines()->create(['chart_of_account_code' => '4.1', 'name' => 'Ventas', 'line_type' => 'income', 'base_amount' => 10000000]);
        $b->lines()->create(['chart_of_account_code' => '5.1', 'name' => 'Costo', 'line_type' => 'cost', 'base_amount' => 6000000]);

        $proj = app(BudgetProjectionService::class)->project($b->fresh('lines'));

        $this->assertCount(5, $proj['years']);
        $y1 = $proj['years'][0];
        $this->assertEquals(10000000, $y1['income']);
        $this->assertEquals(6000000, $y1['cost']);
        $this->assertEquals(4000000, $y1['operating_profit']);
        $this->assertEquals(1000000, $y1['iue']);
        $this->assertEquals(3000000, $y1['net_flow']);
        $this->assertEquals(10300000, $proj['years'][1]['income']);
        $this->assertEquals(6180000, $proj['years'][1]['cost']);
    }
}
