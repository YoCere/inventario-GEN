<?php

namespace Tests\Feature\Fiscal;

use App\Fiscal\SaleTaxCalculator;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleTaxCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_computes_iva_and_it_from_settings(): void
    {
        Setting::set('tax_iva_rate', '13');
        Setting::set('tax_it_rate', '3');

        $r = (new SaleTaxCalculator())->forTotal(10000);

        $this->assertSame(10000, $r['taxable_base']);
        $this->assertSame(1300, $r['iva_amount']);
        $this->assertSame(300, $r['it_amount']);
    }

    public function test_zero_rates_yield_zero(): void
    {
        Setting::set('tax_iva_rate', '0');
        Setting::set('tax_it_rate', '0');
        $r = (new SaleTaxCalculator())->forTotal(10000);
        $this->assertSame(0, $r['iva_amount']);
        $this->assertSame(0, $r['it_amount']);
    }

    public function test_unset_rates_do_not_crash(): void
    {
        $r = (new SaleTaxCalculator())->forTotal(10000);
        $this->assertSame(0, $r['iva_amount']);
        $this->assertSame(0, $r['it_amount']);
    }

    public function test_total_zero(): void
    {
        Setting::set('tax_iva_rate', '13');
        $r = (new SaleTaxCalculator())->forTotal(0);
        $this->assertSame(0, $r['taxable_base']);
        $this->assertSame(0, $r['iva_amount']);
    }
}
