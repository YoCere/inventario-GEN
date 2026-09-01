<?php

namespace Tests\Feature\Accounting;

use App\Fiscal\PurchaseTaxCalculator;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseTaxCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_computes_iva_only(): void
    {
        Setting::set('tax_iva_rate', '13');
        $r = app(PurchaseTaxCalculator::class)->forTotal(10000); // 100.00

        $this->assertSame(10000, $r['taxable_base']);
        $this->assertSame(1300, $r['iva_amount']);
    }
}
