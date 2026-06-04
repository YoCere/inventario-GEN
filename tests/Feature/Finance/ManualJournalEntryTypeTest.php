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

class ManualJournalEntryTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_entry_type_ajuste_creates_ajuste_journal_entry(): void
    {
        $admin = User::factory()->admin()->create();

        $period = AccountingPeriod::create([
            'name'       => 'Junio 2026',
            'start_date' => '2026-06-01',
            'end_date'   => '2026-06-30',
            'status'     => AccountingPeriodStatus::Open->value,
        ]);

        $debitAccount = ChartOfAccount::create([
            'code'           => '1.1.01',
            'name'           => 'Caja',
            'level'          => 3,
            'parent_id'      => null,
            'account_type'   => AccountType::Asset,
            'normal_balance' => AccountNormalBalance::Debit,
            'allows_posting' => true,
            'is_active'      => true,
        ]);

        $creditAccount = ChartOfAccount::create([
            'code'           => '4.1.01',
            'name'           => 'Ventas',
            'level'          => 3,
            'parent_id'      => null,
            'account_type'   => AccountType::Income,
            'normal_balance' => AccountNormalBalance::Credit,
            'allows_posting' => true,
            'is_active'      => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManualJournalEntryForm::class)
            ->set('entry_date', '2026-06-15')
            ->set('voucher_type', VoucherType::Traspaso->value)
            ->set('entry_type', 'ajuste')
            ->set('description', 'Asiento de ajuste de prueba')
            ->set('lines', [
                [
                    'chart_of_account_id' => $debitAccount->id,
                    'side'                => 'debit',
                    'amount'              => '200.00',
                    'description'         => null,
                ],
                [
                    'chart_of_account_id' => $creditAccount->id,
                    'side'                => 'credit',
                    'amount'              => '200.00',
                    'description'         => null,
                ],
            ])
            ->call('save');

        $this->assertEquals(1, JournalEntry::ajustes()->count(), 'Debe existir exactamente 1 asiento de tipo ajuste.');
    }
}
