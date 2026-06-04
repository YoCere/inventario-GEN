<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Models\WorksheetRow;
use App\Services\Accounting\JournalEntryService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorksheetRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_posting_refreshes_worksheet_rows(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        $user = User::factory()->admin()->create();
        $period = AccountingPeriod::resolveOpenForDate('2026-01-15');
        $accs = ChartOfAccount::where('allows_posting', true)->take(2)->get();

        app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-01-15', 'accounting_period_id' => $period->id, 'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $accs[0]->id, 'debit_amount' => 10000],
            ['chart_of_account_id' => $accs[1]->id, 'credit_amount' => 10000],
        ]);

        $this->assertGreaterThan(0, WorksheetRow::where('accounting_period_id', $period->id)->count());
    }
}
