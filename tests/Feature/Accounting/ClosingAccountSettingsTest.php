<?php

namespace Tests\Feature\Accounting;

use App\Models\ChartOfAccount;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClosingAccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_and_accounts(): void
    {
        $this->assertSame('6.8', Setting::get('accounting_iue_expense_code'));
        $this->assertSame('2.1.13', Setting::get('accounting_iue_payable_code'));
        $this->assertSame('3.3', Setting::get('accounting_period_result_code'));

        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);
        $this->assertNotNull(ChartOfAccount::where('code', '2.1.13')->first());
        $this->assertNotNull(ChartOfAccount::where('code', '6.8')->first());
        $this->assertNotNull(ChartOfAccount::where('code', '3.4')->first());
    }
}
