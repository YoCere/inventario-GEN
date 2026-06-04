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

class LedgerSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
    }

    private function postEntry(string $date, int $debitAcc, int $creditAcc, int $amount, string $type = 'normal'): void
    {
        $user = User::factory()->admin()->create();
        $period = AccountingPeriod::resolveOpenForDate($date);
        app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => $date,
            'accounting_period_id' => $period->id,
            'entry_type' => $type,
            'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $debitAcc, 'debit_amount' => $amount],
            ['chart_of_account_id' => $creditAcc, 'credit_amount' => $amount],
        ]);
    }

    public function test_snapshot_accumulates_same_account_same_day(): void
    {
        $accs = ChartOfAccount::where('allows_posting', true)->take(2)->get();
        $this->postEntry('2026-01-15', $accs[0]->id, $accs[1]->id, 10000);
        $this->postEntry('2026-01-15', $accs[0]->id, $accs[1]->id, 5000);

        $row = LedgerAccountDaily::where('chart_of_account_id', $accs[0]->id)
            ->where('movement_date', '2026-01-15')
            ->where('entry_type', 'normal')
            ->first();

        $this->assertEquals(15000, $row->debit_total);
        $this->assertEquals(0, $row->credit_total);
    }

    public function test_snapshot_separates_normal_from_ajuste(): void
    {
        $accs = ChartOfAccount::where('allows_posting', true)->take(2)->get();
        $this->postEntry('2026-01-31', $accs[0]->id, $accs[1]->id, 10000, 'normal');
        $this->postEntry('2026-01-31', $accs[0]->id, $accs[1]->id, 600, 'ajuste');

        $this->assertEquals(2, LedgerAccountDaily::where('chart_of_account_id', $accs[0]->id)
            ->where('movement_date', '2026-01-31')->count());
    }
}
