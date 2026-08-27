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

class ClosedPeriodOpeningTest extends TestCase
{
    use RefreshDatabase;

    private function seedCoa(): array
    {
        $caja = ChartOfAccount::create(['code' => '1.1.01', 'name' => 'Caja', 'level' => 3, 'account_type' => 'asset', 'normal_balance' => 'debit', 'allows_posting' => true, 'is_active' => true]);
        $cap  = ChartOfAccount::create(['code' => '3.1', 'name' => 'Capital', 'level' => 2, 'account_type' => 'equity', 'normal_balance' => 'credit', 'allows_posting' => true, 'is_active' => true]);
        return [$caja, $cap];
    }

    public function test_opening_can_post_to_closed_period_with_flag(): void
    {
        [$caja, $cap] = $this->seedCoa();
        $user = User::factory()->create();
        $period = AccountingPeriod::create(['name' => 'Gestion 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Closed]);

        $entry = app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-01-01', 'accounting_period_id' => $period->id,
            'entry_type' => JournalEntryType::Apertura->value, 'voucher_type' => VoucherType::Apertura->value,
            'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $caja->id, 'debit_amount' => 100000],
            ['chart_of_account_id' => $cap->id, 'credit_amount' => 100000],
        ], allowClosedPeriod: true);

        $this->assertSame('apertura', $entry->entry_type->value);
    }

    public function test_normal_entry_still_blocked_on_closed_period(): void
    {
        [$caja, $cap] = $this->seedCoa();
        $user = User::factory()->create();
        $period = AccountingPeriod::create(['name' => 'Gestion 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Closed]);

        $this->expectException(RuntimeException::class);
        app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-01-01', 'accounting_period_id' => $period->id, 'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $caja->id, 'debit_amount' => 100000],
            ['chart_of_account_id' => $cap->id, 'credit_amount' => 100000],
        ]);
    }

    public function test_allow_closed_period_only_for_apertura(): void
    {
        [$caja, $cap] = $this->seedCoa();
        $user = User::factory()->create();
        $period = AccountingPeriod::create(['name' => 'Gestion 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Closed]);

        $this->expectException(RuntimeException::class);
        app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-01-01', 'accounting_period_id' => $period->id,
            'entry_type' => JournalEntryType::Normal->value, 'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $caja->id, 'debit_amount' => 100000],
            ['chart_of_account_id' => $cap->id, 'credit_amount' => 100000],
        ], allowClosedPeriod: true);
    }
}
