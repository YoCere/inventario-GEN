<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\LedgerAccountDaily;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LedgerRebuildTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebuild_reconciles_snapshot_with_journal_lines(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        $user = User::factory()->admin()->create();
        $period = AccountingPeriod::resolveOpenForDate('2026-01-15');
        $accs = ChartOfAccount::where('allows_posting', true)->take(2)->get();

        app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-01-15', 'accounting_period_id' => $period->id, 'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $accs[0]->id, 'debit_amount' => 20000],
            ['chart_of_account_id' => $accs[1]->id, 'credit_amount' => 20000],
        ]);

        // Corromper el snapshot a propósito.
        LedgerAccountDaily::query()->update(['debit_total' => 999999]);

        $this->artisan('ledger:rebuild')->assertExitCode(0);

        $row = LedgerAccountDaily::where('chart_of_account_id', $accs[0]->id)->first();
        $this->assertEquals(20000, $row->debit_total);

        // Idempotente: correr de nuevo no cambia nada.
        $this->artisan('ledger:rebuild')->assertExitCode(0);
        $this->assertEquals(20000, LedgerAccountDaily::where('chart_of_account_id', $accs[0]->id)->first()->debit_total);
    }

    public function test_rebuild_after_reversal_matches_live_snapshot(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        $user  = User::factory()->admin()->create();
        $period = AccountingPeriod::resolveOpenForDate('2026-01-15');
        $accs  = ChartOfAccount::where('allows_posting', true)->take(2)->get();
        $svc   = app(JournalEntryService::class);

        // Post an entry then reverse it — the live snapshot should net to 0.
        $entry = $svc->createPostedEntry([
            'entry_date'           => '2026-01-15',
            'accounting_period_id' => $period->id,
            'created_by'           => $user->id,
        ], [
            ['chart_of_account_id' => $accs[0]->id, 'debit_amount'  => 20000],
            ['chart_of_account_id' => $accs[1]->id, 'credit_amount' => 20000],
        ]);

        $svc->reverseEntry($entry, $user->id);

        // Capture the live snapshot per account for the two accounts involved.
        $liveDebit0  = (int) DB::table('ledger_account_daily')->where('chart_of_account_id', $accs[0]->id)->sum('debit_total');
        $liveCredit0 = (int) DB::table('ledger_account_daily')->where('chart_of_account_id', $accs[0]->id)->sum('credit_total');
        $liveDebit1  = (int) DB::table('ledger_account_daily')->where('chart_of_account_id', $accs[1]->id)->sum('debit_total');
        $liveCredit1 = (int) DB::table('ledger_account_daily')->where('chart_of_account_id', $accs[1]->id)->sum('credit_total');

        // Corrupt and rebuild.
        LedgerAccountDaily::query()->update(['debit_total' => 999999, 'credit_total' => 999999]);

        $this->artisan('ledger:rebuild')->assertExitCode(0);

        // Rebuilt totals must equal live totals (reversed pair nets to 0 debit and 0 credit).
        $this->assertEquals($liveDebit0,  (int) DB::table('ledger_account_daily')->where('chart_of_account_id', $accs[0]->id)->sum('debit_total'));
        $this->assertEquals($liveCredit0, (int) DB::table('ledger_account_daily')->where('chart_of_account_id', $accs[0]->id)->sum('credit_total'));
        $this->assertEquals($liveDebit1,  (int) DB::table('ledger_account_daily')->where('chart_of_account_id', $accs[1]->id)->sum('debit_total'));
        $this->assertEquals($liveCredit1, (int) DB::table('ledger_account_daily')->where('chart_of_account_id', $accs[1]->id)->sum('credit_total'));
    }
}
