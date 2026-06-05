<?php

namespace Tests\Feature\Accounting;

use App\Services\Accounting\AmortizationCalculator;
use Tests\TestCase;

class AmortizationCalculatorTest extends TestCase
{
    private function calc(): AmortizationCalculator
    {
        return new AmortizationCalculator();
    }

    public function test_french_schedule_hand_checked(): void
    {
        // P=12.000 Bs (1.200.000 cent), 12% anual (1% mensual), 12 meses.
        // Cuota francesa = 1.200.000 * 0.01 / (1 - 1.01^-12) ≈ 106.619 cent.
        $sch = $this->calc()->schedule(1200000, 12.0, 12, '2026-01-01', 5);

        $this->assertCount(12, $sch);
        $this->assertEqualsWithDelta(106619, $sch[0]['payment_amount'], 2);
        $this->assertGreaterThan($sch[11]['interest_amount'], $sch[0]['interest_amount']);
        $this->assertLessThan($sch[11]['principal_amount'], $sch[0]['principal_amount']);
        $this->assertEquals(1200000, collect($sch)->sum('principal_amount'));
        $this->assertEquals(0, $sch[11]['balance_after']);
        $this->assertEquals(12000, $sch[0]['interest_amount']); // 1.200.000 * 0.01
        $this->assertEquals('2026-02-05', $sch[0]['due_date']);
    }

    public function test_zero_rate(): void
    {
        $sch = $this->calc()->schedule(1200000, 0.0, 12, '2026-01-01', 1);
        $this->assertEquals(100000, $sch[0]['payment_amount']); // 1.200.000 / 12
        $this->assertEquals(0, $sch[0]['interest_amount']);
        $this->assertEquals(0, $sch[11]['balance_after']);
        $this->assertEquals(1200000, collect($sch)->sum('principal_amount'));
    }

    public function test_excel_loan_structure(): void
    {
        // Préstamo del Excel: 180.652,28 Bs, ~5,5% anual, 60 meses.
        $sch = $this->calc()->schedule(18065228, 5.5, 60, '2026-01-01', 1);
        $this->assertCount(60, $sch);
        $this->assertEquals(18065228, collect($sch)->sum('principal_amount'));
        $this->assertEquals(0, $sch[59]['balance_after']);
        $this->assertEqualsWithDelta($sch[0]['payment_amount'], $sch[30]['payment_amount'], 2);
    }
}
