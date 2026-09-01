<?php

namespace Tests\Feature\Accounting;

use App\Models\ChartOfAccount;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxAccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tax_settings_and_it_expense_account_seeded(): void
    {
        $this->assertSame('2.1.11', Setting::get('accounting_df_iva_code'));
        $this->assertSame('1.1.05', Setting::get('accounting_cf_iva_code'));
        $this->assertSame('2.1.12', Setting::get('accounting_it_payable_code'));
        $this->assertSame('6.7', Setting::get('accounting_it_expense_code'));
        $this->assertSame('13', Setting::get('tax_iva_rate'));
        $this->assertSame('3', Setting::get('tax_it_rate'));

        $itExpense = ChartOfAccount::where('code', '6.7')->first();
        $this->assertNotNull($itExpense);
        $this->assertSame('expense', $itExpense->account_type->value);
        $this->assertTrue((bool) $itExpense->allows_posting);
    }
}
