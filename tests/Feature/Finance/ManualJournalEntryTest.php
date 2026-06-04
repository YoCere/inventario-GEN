<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountNormalBalance;
use App\Enums\AccountType;
use App\Enums\VoucherType;
use App\Livewire\FinanceJournalEntries\ManualJournalEntryForm;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ManualJournalEntryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;
    private AccountingPeriod $period;
    private ChartOfAccount $debitAccount;
    private ChartOfAccount $creditAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->staff = User::factory()->staff()->create();

        $this->period = AccountingPeriod::create([
            'name'       => 'Junio 2026',
            'start_date' => '2026-06-01',
            'end_date'   => '2026-06-30',
            'status'     => AccountingPeriodStatus::Open->value,
        ]);

        $this->debitAccount = ChartOfAccount::create([
            'code'           => '1.1.01',
            'name'           => 'Caja',
            'level'          => 3,
            'parent_id'      => null,
            'account_type'   => AccountType::Asset,
            'normal_balance' => AccountNormalBalance::Debit,
            'allows_posting' => true,
            'is_active'      => true,
        ]);

        $this->creditAccount = ChartOfAccount::create([
            'code'           => '4.1.01',
            'name'           => 'Ventas',
            'level'          => 3,
            'parent_id'      => null,
            'account_type'   => AccountType::Income,
            'normal_balance' => AccountNormalBalance::Credit,
            'allows_posting' => true,
            'is_active'      => true,
        ]);
    }

    public function test_admin_can_create_balanced_journal_entry(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ManualJournalEntryForm::class)
            ->set('entry_date', '2026-06-10')
            ->set('voucher_type', VoucherType::Ingreso->value)
            ->set('description', 'Venta al contado')
            ->set('lines', [
                [
                    'chart_of_account_id' => $this->debitAccount->id,
                    'side'                => 'debit',
                    'amount'              => '150.00',
                    'description'         => 'Ingreso caja',
                ],
                [
                    'chart_of_account_id' => $this->creditAccount->id,
                    'side'                => 'credit',
                    'amount'              => '150.00',
                    'description'         => 'Venta',
                ],
            ])
            ->call('save');

        $entry = JournalEntry::first();
        $this->assertNotNull($entry, 'Debe existir un JournalEntry.');
        $this->assertEquals('posted', $entry->status->value);
        $this->assertEquals(VoucherType::Ingreso->value, $entry->voucher_type->value);
        $this->assertNotNull($entry->voucher_number);
        $this->assertCount(2, $entry->lines);

        $debitLine  = $entry->lines->firstWhere('debit_amount', '>', 0);
        $creditLine = $entry->lines->firstWhere('credit_amount', '>', 0);

        $this->assertNotNull($debitLine);
        $this->assertNotNull($creditLine);
        $this->assertEquals(15000, $debitLine->debit_amount);   // 150.00 Bs → 15000 centavos
        $this->assertEquals(15000, $creditLine->credit_amount); // 150.00 Bs → 15000 centavos
    }

    public function test_unbalanced_entry_is_rejected_and_no_journal_entry_is_created(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ManualJournalEntryForm::class)
            ->set('entry_date', '2026-06-10')
            ->set('voucher_type', VoucherType::Egreso->value)
            ->set('lines', [
                [
                    'chart_of_account_id' => $this->debitAccount->id,
                    'side'                => 'debit',
                    'amount'              => '100.00',
                    'description'         => null,
                ],
                [
                    'chart_of_account_id' => $this->creditAccount->id,
                    'side'                => 'credit',
                    'amount'              => '200.00',   // does not match debit
                    'description'         => null,
                ],
            ])
            ->call('save');

        $this->assertEquals(0, JournalEntry::count(), 'No debe crearse asiento cuando no cuadra.');
    }

    public function test_single_line_fails_validation(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ManualJournalEntryForm::class)
            ->set('entry_date', '2026-06-10')
            ->set('voucher_type', VoucherType::Traspaso->value)
            ->set('lines', [
                [
                    'chart_of_account_id' => $this->debitAccount->id,
                    'side'                => 'debit',
                    'amount'              => '50.00',
                    'description'         => null,
                ],
            ])
            ->call('save')
            ->assertHasErrors(['lines']);
    }

    public function test_missing_account_fails_validation(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ManualJournalEntryForm::class)
            ->set('entry_date', '2026-06-10')
            ->set('voucher_type', VoucherType::Ingreso->value)
            ->set('lines', [
                [
                    'chart_of_account_id' => null,   // missing
                    'side'                => 'debit',
                    'amount'              => '50.00',
                    'description'         => null,
                ],
                [
                    'chart_of_account_id' => $this->creditAccount->id,
                    'side'                => 'credit',
                    'amount'              => '50.00',
                    'description'         => null,
                ],
            ])
            ->call('save')
            ->assertHasErrors(['lines.0.chart_of_account_id']);
    }

    public function test_non_admin_gets_403(): void
    {
        // abort_if(403) in Livewire is handled by the framework and returns a
        // 403 HTTP response rather than propagating a thrown exception in tests.
        // Assert on the response status via Livewire's __call proxy.
        Livewire::actingAs($this->staff)
            ->test(ManualJournalEntryForm::class)
            ->set('entry_date', '2026-06-10')
            ->set('voucher_type', VoucherType::Ingreso->value)
            ->set('lines', [
                [
                    'chart_of_account_id' => $this->debitAccount->id,
                    'side'                => 'debit',
                    'amount'              => '50.00',
                    'description'         => null,
                ],
                [
                    'chart_of_account_id' => $this->creditAccount->id,
                    'side'                => 'credit',
                    'amount'              => '50.00',
                    'description'         => null,
                ],
            ])
            ->call('save')
            ->assertForbidden();
    }
}
