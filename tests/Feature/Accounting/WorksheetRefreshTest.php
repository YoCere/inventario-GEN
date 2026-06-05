<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Models\WorksheetRow;
use App\Services\Accounting\JournalEntryService;
use App\Services\Accounting\WorksheetService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorksheetRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_post_to_fresh_period_does_not_create_worksheet_rows(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        $user   = User::factory()->admin()->create();
        $period = AccountingPeriod::resolveOpenForDate('2026-01-15');
        $accs   = ChartOfAccount::where('allows_posting', true)->take(2)->get();

        // No worksheet rows exist yet for this period.
        $this->assertEquals(0, WorksheetRow::where('accounting_period_id', $period->id)->count());

        app(JournalEntryService::class)->createPostedEntry([
            'entry_date'           => '2026-01-15',
            'accounting_period_id' => $period->id,
            'created_by'           => $user->id,
        ], [
            ['chart_of_account_id' => $accs[0]->id, 'debit_amount'  => 10000],
            ['chart_of_account_id' => $accs[1]->id, 'credit_amount' => 10000],
        ]);

        // Listener is gated: no rows should be created for a never-viewed period.
        $this->assertEquals(0, WorksheetRow::where('accounting_period_id', $period->id)->count());
    }

    public function test_post_after_initial_generate_refreshes_worksheet_rows(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        $user   = User::factory()->admin()->create();
        $period = AccountingPeriod::resolveOpenForDate('2026-01-15');
        $accs   = ChartOfAccount::where('allows_posting', true)->take(2)->get();

        // Post first entry.
        app(JournalEntryService::class)->createPostedEntry([
            'entry_date'           => '2026-01-15',
            'accounting_period_id' => $period->id,
            'created_by'           => $user->id,
        ], [
            ['chart_of_account_id' => $accs[0]->id, 'debit_amount'  => 10000],
            ['chart_of_account_id' => $accs[1]->id, 'credit_amount' => 10000],
        ]);

        // Simulate opening the Hoja Teórica (on-demand generation).
        app(WorksheetService::class)->generate($period);
        $rowsAfterView = WorksheetRow::where('accounting_period_id', $period->id)->count();
        $this->assertGreaterThan(0, $rowsAfterView);

        // Post a second entry — listener now finds existing rows and refreshes.
        app(JournalEntryService::class)->createPostedEntry([
            'entry_date'           => '2026-01-15',
            'accounting_period_id' => $period->id,
            'created_by'           => $user->id,
        ], [
            ['chart_of_account_id' => $accs[0]->id, 'debit_amount'  => 5000],
            ['chart_of_account_id' => $accs[1]->id, 'credit_amount' => 5000],
        ]);

        $this->assertGreaterThan(0, WorksheetRow::where('accounting_period_id', $period->id)->count());
    }
}
