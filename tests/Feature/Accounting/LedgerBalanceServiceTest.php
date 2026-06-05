<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use App\Services\Accounting\LedgerBalanceService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerBalanceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_balances_at_cutoff_excludes_later_movements(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        $user = User::factory()->admin()->create();
        $period = AccountingPeriod::resolveOpenForDate('2026-01-15');
        // Selección determinística: activo de naturaleza deudora (excluye contra-activos
        // como Depreciación Acumulada, naturaleza acreedora) y patrimonio acreedor.
        $caja = ChartOfAccount::where('allows_posting', true)->where('account_type', 'asset')
            ->where('normal_balance', 'debit')->orderBy('code')->first();
        $cap  = ChartOfAccount::where('allows_posting', true)->where('account_type', 'equity')
            ->where('normal_balance', 'credit')->orderBy('code')->first();

        app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-01-01', 'accounting_period_id' => $period->id, 'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $caja->id, 'debit_amount' => 2000000],
            ['chart_of_account_id' => $cap->id, 'credit_amount' => 2000000],
        ]);
        app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-01-20', 'accounting_period_id' => $period->id, 'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $caja->id, 'debit_amount' => 300000],
            ['chart_of_account_id' => $cap->id, 'credit_amount' => 300000],
        ]);

        $svc = app(LedgerBalanceService::class);
        $atMid = $svc->balancesAt('2026-01-15')->firstWhere('chart_of_account_id', $caja->id);
        $atEnd = $svc->balancesAt('2026-01-31')->firstWhere('chart_of_account_id', $caja->id);

        $this->assertEquals(2000000, $atMid->balance);
        $this->assertEquals(2300000, $atEnd->balance);
    }
}
