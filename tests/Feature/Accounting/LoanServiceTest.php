<?php

namespace Tests\Feature\Accounting;

use App\Enums\InstallmentStatus;
use App\Enums\LoanStatus;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\User;
use App\Services\Accounting\LoanService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        $this->userId = User::factory()->admin()->create()->id;
    }

    private function data(array $over = []): array
    {
        return array_merge([
            'lender' => 'Banco X', 'code' => 'L-001', 'principal' => 1200000,
            'annual_rate_pct' => 12.0, 'term_months' => 12, 'start_date' => '2026-01-01', 'payment_day' => 5,
            'liability_account_code' => '2.2.01', 'interest_account_code' => '6.3', 'payment_account_code' => '1.1.02',
        ], $over);
    }

    public function test_register_new_posts_disbursement_and_schedule(): void
    {
        $loan = app(LoanService::class)->registerNew($this->data(), $this->userId);

        $this->assertEquals(12, $loan->installments()->count());
        $this->assertEquals(1200000, $loan->outstanding_balance);
        $this->assertNotNull($loan->disbursement_entry_id);

        $entry = JournalEntry::with('lines')->find($loan->disbursement_entry_id);
        $banco = ChartOfAccount::where('code', '1.1.02')->first();
        $prestamo = ChartOfAccount::where('code', '2.2.01')->first();
        $this->assertEquals(1200000, $entry->lines->where('chart_of_account_id', $banco->id)->sum('debit_amount'));
        $this->assertEquals(1200000, $entry->lines->where('chart_of_account_id', $prestamo->id)->sum('credit_amount'));
    }

    public function test_register_payment_posts_balanced_entry_and_reduces_balance(): void
    {
        $loan = app(LoanService::class)->registerNew($this->data(), $this->userId);
        $first = $loan->installments()->orderBy('number')->first();

        app(LoanService::class)->registerPayment($first, '2026-02-05', null, $this->userId);

        $first->refresh();
        $this->assertEquals(InstallmentStatus::Paid, $first->status);
        $entry = JournalEntry::with('lines')->find($first->journal_entry_id);
        $this->assertEquals($entry->lines->sum('debit_amount'), $entry->lines->sum('credit_amount'));
        $banco = ChartOfAccount::where('code', '1.1.02')->first();
        $this->assertEquals($first->payment_amount, $entry->lines->where('chart_of_account_id', $banco->id)->sum('credit_amount'));
        $this->assertEquals(1200000 - $first->principal_amount, $loan->fresh()->outstanding_balance);
    }

    public function test_register_payment_is_idempotent(): void
    {
        $loan = app(LoanService::class)->registerNew($this->data(), $this->userId);
        $first = $loan->installments()->orderBy('number')->first();

        app(LoanService::class)->registerPayment($first, '2026-02-05', null, $this->userId);
        app(LoanService::class)->registerPayment($first->fresh(), '2026-02-05', null, $this->userId);

        $this->assertEquals(1, JournalEntry::where('id', $first->fresh()->journal_entry_id)->count());
        $this->assertEquals(11, $loan->installments()->where('status', 'pending')->count());
    }

    public function test_paying_all_installments_marks_paid_off(): void
    {
        $loan = app(LoanService::class)->registerNew($this->data(), $this->userId);
        foreach ($loan->installments()->orderBy('number')->get() as $inst) {
            app(LoanService::class)->registerPayment($inst, $inst->due_date->toDateString(), null, $this->userId);
        }
        $loan->refresh();
        $this->assertEquals(LoanStatus::PaidOff, $loan->status);
        $this->assertEquals(0, $loan->outstanding_balance);
    }

    public function test_register_opening_no_disbursement_marks_past_paid(): void
    {
        $loan = app(LoanService::class)->registerOpening($this->data(['code' => 'L-OPEN']), '2026-04-01', $this->userId);

        $this->assertTrue($loan->is_opening);
        $this->assertNull($loan->disbursement_entry_id);
        $this->assertEquals(2, $loan->installments()->where('status', 'paid')->count());
        $this->assertNull($loan->installments()->where('status', 'paid')->first()->journal_entry_id);
        $lastPaid = $loan->installments()->where('status', 'paid')->orderByDesc('number')->first();
        $this->assertEquals($lastPaid->balance_after, $loan->outstanding_balance);
    }

    public function test_payoff_cancels_balance(): void
    {
        $loan = app(LoanService::class)->registerNew($this->data(['code' => 'L-PO']), $this->userId);
        app(LoanService::class)->payoff($loan, '2026-03-01', null, $this->userId);

        $loan->refresh();
        $this->assertEquals(LoanStatus::PaidOff, $loan->status);
        $this->assertEquals(0, $loan->outstanding_balance);
        $this->assertEquals(0, $loan->installments()->where('status', 'pending')->count());
    }
}
