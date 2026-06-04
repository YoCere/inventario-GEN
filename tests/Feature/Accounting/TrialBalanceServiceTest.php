<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use App\Services\Accounting\TrialBalanceService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrialBalanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private array $acc = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        $this->acc['caja']        = $this->coa('1.1.1.01', 'Caja/Banco', 'asset', 'debit');
        $this->acc['suministros'] = $this->coa('1.1.2.01', 'Suministros', 'asset', 'debit');
        $this->acc['proveedores'] = $this->coa('2.1.1.01', 'Proveedores', 'liability', 'credit');
        $this->acc['capital']     = $this->coa('3.1.1.01', 'Capital social', 'equity', 'credit');
        $this->acc['ingresos']    = $this->coa('4.1.1.01', 'Ingresos por servicios', 'income', 'credit');
        $this->acc['alquiler']    = $this->coa('5.1.1.01', 'Gastos de alquiler', 'expense', 'debit');
        $this->acc['gasto_sum']   = $this->coa('5.1.1.02', 'Gasto suministros', 'expense', 'debit');
        $this->postExample();
    }

    private function coa(string $code, string $name, string $type, string $nb): ChartOfAccount
    {
        return ChartOfAccount::firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'account_type' => $type, 'normal_balance' => $nb,
             'allows_posting' => true, 'is_active' => true, 'level' => 4]
        );
    }

    private function entry(string $date, array $lines, string $type = 'normal'): void
    {
        $user = User::factory()->admin()->create();
        $period = AccountingPeriod::resolveOpenForDate($date);
        app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => $date, 'accounting_period_id' => $period->id,
            'entry_type' => $type, 'created_by' => $user->id,
        ], $lines);
    }

    private function postExample(): void
    {
        $this->entry('2026-01-01', [
            ['chart_of_account_id' => $this->acc['caja']->id, 'debit_amount' => 2000000],
            ['chart_of_account_id' => $this->acc['capital']->id, 'credit_amount' => 2000000],
        ]);
        $this->entry('2026-01-05', [
            ['chart_of_account_id' => $this->acc['alquiler']->id, 'debit_amount' => 150000],
            ['chart_of_account_id' => $this->acc['caja']->id, 'credit_amount' => 150000],
        ]);
        $this->entry('2026-01-10', [
            ['chart_of_account_id' => $this->acc['suministros']->id, 'debit_amount' => 80000],
            ['chart_of_account_id' => $this->acc['proveedores']->id, 'credit_amount' => 80000],
        ]);
        $this->entry('2026-01-15', [
            ['chart_of_account_id' => $this->acc['caja']->id, 'debit_amount' => 300000],
            ['chart_of_account_id' => $this->acc['ingresos']->id, 'credit_amount' => 300000],
        ]);
    }

    public function test_unadjusted_trial_balance_totals(): void
    {
        $period = AccountingPeriod::resolveOpenForDate('2026-01-31');
        $tb = app(TrialBalanceService::class)->build($period, adjusted: false);

        $this->assertEquals(2530000, $tb['totales']['sumas_debe']);
        $this->assertEquals(2530000, $tb['totales']['sumas_haber']);
        $this->assertEquals(2380000, $tb['totales']['saldo_deudor']);
        $this->assertEquals(2380000, $tb['totales']['saldo_acreedor']);
        $this->assertTrue($tb['cuadra']);
    }

    public function test_adjusted_trial_balance_after_consumption_entry(): void
    {
        $this->entry('2026-01-31', [
            ['chart_of_account_id' => $this->acc['gasto_sum']->id, 'debit_amount' => 60000],
            ['chart_of_account_id' => $this->acc['suministros']->id, 'credit_amount' => 60000],
        ], 'ajuste');

        $period = AccountingPeriod::resolveOpenForDate('2026-01-31');
        $tb = app(TrialBalanceService::class)->build($period, adjusted: true);

        $this->assertEquals(2380000, $tb['totales']['saldo_deudor']);
        $this->assertEquals(2380000, $tb['totales']['saldo_acreedor']);
        $this->assertTrue($tb['cuadra']);
    }
}
