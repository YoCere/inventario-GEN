<?php

namespace Tests\Feature\Accounting;

use App\Livewire\Loans\LoanForm;
use App\Livewire\Loans\LoanTable;
use App\Models\Loan;
use App\Models\User;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoanPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
    }

    public function test_loan_table_renders(): void
    {
        $admin = User::factory()->admin()->create();
        Livewire::actingAs($admin)->test(LoanTable::class)->assertOk();
    }

    public function test_admin_registers_loan_via_form(): void
    {
        $admin = User::factory()->admin()->create();
        Livewire::actingAs($admin)->test(LoanForm::class)
            ->set('mode', 'new')->set('lender', 'Banco X')->set('code', 'L-100')->set('principal', 12000)
            ->set('annual_rate_pct', 12)->set('term_months', 12)->set('start_date', '2026-01-01')->set('payment_day', 5)
            ->set('liability_account_code', '2.2.01')->set('interest_account_code', '6.3')->set('payment_account_code', '1.1.02')
            ->call('save')->assertHasNoErrors();
        $loan = Loan::where('code', 'L-100')->first();
        $this->assertNotNull($loan);
        $this->assertEquals(1200000, $loan->principal);
        $this->assertEquals(12, $loan->installments()->count());
        $this->assertNotNull($loan->disbursement_entry_id);
    }
}
