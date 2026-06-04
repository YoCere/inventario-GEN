<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\Accounting\FinancialReadModel;
use App\Services\Accounting\JournalEntryService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialReadModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_income_statement_reports_ingresos_y_gastos(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        $ingresos = ChartOfAccount::firstOrCreate(['code' => '4.1.1.01'],
            ['name' => 'Ingresos', 'account_type' => 'income', 'normal_balance' => 'credit',
             'allows_posting' => true, 'is_active' => true, 'level' => 4]);
        $alquiler = ChartOfAccount::firstOrCreate(['code' => '5.1.1.01'],
            ['name' => 'Alquiler', 'account_type' => 'expense', 'normal_balance' => 'debit',
             'allows_posting' => true, 'is_active' => true, 'level' => 4]);
        $caja = ChartOfAccount::firstOrCreate(['code' => '1.1.1.01'],
            ['name' => 'Caja', 'account_type' => 'asset', 'normal_balance' => 'debit',
             'allows_posting' => true, 'is_active' => true, 'level' => 4]);

        $user = User::factory()->admin()->create();
        $period = AccountingPeriod::resolveOpenForDate('2026-01-15');
        app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-01-15', 'accounting_period_id' => $period->id, 'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $caja->id, 'debit_amount' => 300000],
            ['chart_of_account_id' => $ingresos->id, 'credit_amount' => 300000],
        ]);
        app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-01-16', 'accounting_period_id' => $period->id, 'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $alquiler->id, 'debit_amount' => 150000],
            ['chart_of_account_id' => $caja->id, 'credit_amount' => 150000],
        ]);

        $is = app(FinancialReadModel::class)->incomeStatement('2026-01-01', '2026-01-31');
        $this->assertEquals(3000.0, $is['ingresos']);
        $this->assertEquals(1500.0, $is['gastos']);
    }

    public function test_status_at_cutoff(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        $caja = ChartOfAccount::firstOrCreate(['code' => '1.1.1.01'],
            ['name' => 'Caja', 'account_type' => 'asset', 'normal_balance' => 'debit',
             'allows_posting' => true, 'is_active' => true, 'level' => 4]);
        $cap = ChartOfAccount::firstOrCreate(['code' => '3.1.1.01'],
            ['name' => 'Capital', 'account_type' => 'equity', 'normal_balance' => 'credit',
             'allows_posting' => true, 'is_active' => true, 'level' => 4]);
        $user = User::factory()->admin()->create();
        $period = AccountingPeriod::resolveOpenForDate('2026-01-15');
        app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-01-10', 'accounting_period_id' => $period->id, 'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $caja->id, 'debit_amount' => 2000000],
            ['chart_of_account_id' => $cap->id, 'credit_amount' => 2000000],
        ]);

        $status = app(FinancialReadModel::class)->statusAt('2026-01-31');
        $this->assertEquals(20000.0, $status['activos_bs']);
        $this->assertEquals(20000.0, $status['patrimonio_bs']);
    }
}
