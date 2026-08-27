<?php

namespace Tests\Feature\Accounting;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpeningAccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_account_codes_are_seeded(): void
    {
        $this->assertSame('1.1.01', Setting::get('accounting_opening_cash_code'));
        $this->assertSame('1.1.04', Setting::get('accounting_opening_inventory_code'));
        $this->assertSame('3.1', Setting::get('accounting_opening_capital_code'));
        $this->assertSame('1.2.02', Setting::get('accounting_opening_depreciation_code'));
    }
}
