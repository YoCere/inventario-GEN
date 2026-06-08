<?php

namespace Tests\Feature\Accounting;

use App\Services\Accounting\InvestmentMetrics;
use Tests\TestCase;

class InvestmentMetricsTest extends TestCase
{
    public function test_npv_and_irr_and_payback(): void
    {
        $m = new InvestmentMetrics();
        $series = [-100, 60, 60, 60];
        $this->assertGreaterThan(0, $m->npv($series, 0.10));

        $irr = $m->irr($series);
        $this->assertNotNull($irr);
        $this->assertGreaterThan(0.30, $irr);
        $this->assertLessThan(0.40, $irr);

        $pb = $m->payback(100, [60, 60, 60]);
        $this->assertEqualsWithDelta(1.67, $pb, 0.01);

        $this->assertNull($m->irr([100, 60, 60]));
        $this->assertNull($m->payback(0, [60]));
    }
}
