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
}
