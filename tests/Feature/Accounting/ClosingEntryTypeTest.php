<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountingPeriodStatus;
use App\Enums\JournalEntryType;
use App\Enums\VoucherType;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ClosingEntryTypeTest extends TestCase
{
    use RefreshDatabase;

    private function seedAccounts(): array
    {
        $a = ChartOfAccount::create(['code' => '6.8', 'name' => 'IUE', 'level' => 2, 'account_type' => 'expense', 'normal_balance' => 'debit', 'allows_posting' => true, 'is_active' => true]);
        $b = ChartOfAccount::create(['code' => '2.1.13', 'name' => 'IUE x Pagar', 'level' => 3, 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => true, 'is_active' => true]);
        return [$a, $b];
    }

    public function test_cierre_puede_postear_a_periodo_cerrado(): void
    {
        [$a, $b] = $this->seedAccounts();
        $user = User::factory()->create();
        $period = AccountingPeriod::create(['name' => 'G2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Closed]);

        $entry = app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-12-31', 'accounting_period_id' => $period->id,
            'entry_type' => JournalEntryType::Cierre->value, 'voucher_type' => VoucherType::Cierre->value,
            'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $a->id, 'debit_amount' => 100000],
            ['chart_of_account_id' => $b->id, 'credit_amount' => 100000],
        ], allowClosedPeriod: true);

        $this->assertSame('cierre', $entry->entry_type->value);
    }

    public function test_reverso_de_cierre_bloqueado(): void
    {
        [$a, $b] = $this->seedAccounts();
        $user = User::factory()->create();
        $period = AccountingPeriod::create(['name' => 'G2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Open]);
        $entry = app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-12-31', 'accounting_period_id' => $period->id,
            'entry_type' => JournalEntryType::Cierre->value, 'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $a->id, 'debit_amount' => 100000],
            ['chart_of_account_id' => $b->id, 'credit_amount' => 100000],
        ]);

        $this->expectException(RuntimeException::class);
        app(JournalEntryService::class)->reverseEntry($entry, $user->id);
    }
}
