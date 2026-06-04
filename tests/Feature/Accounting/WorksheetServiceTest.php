<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use App\Services\Accounting\WorksheetService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorksheetServiceTest extends TestCase
{
    use RefreshDatabase;

    private array $acc = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        $map = [
            ['1.1.1.01', 'Caja/Banco', 'asset', 'debit', 'caja'],
            ['1.1.2.01', 'Suministros', 'asset', 'debit', 'suministros'],
            ['2.1.1.01', 'Proveedores', 'liability', 'credit', 'proveedores'],
            ['3.1.1.01', 'Capital social', 'equity', 'credit', 'capital'],
            ['4.1.1.01', 'Ingresos por servicios', 'income', 'credit', 'ingresos'],
            ['5.1.1.01', 'Gastos de alquiler', 'expense', 'debit', 'alquiler'],
            ['5.1.1.02', 'Gasto suministros', 'expense', 'debit', 'gasto_sum'],
        ];
        foreach ($map as [$code, $name, $type, $nb, $key]) {
            $this->acc[$key] = ChartOfAccount::firstOrCreate(['code' => $code],
                ['name' => $name, 'account_type' => $type, 'normal_balance' => $nb,
                 'allows_posting' => true, 'is_active' => true, 'level' => 4]);
        }
        $this->postEntry('2026-01-01', $this->acc['caja']->id, $this->acc['capital']->id, 2000000);
        $this->postEntry('2026-01-05', $this->acc['alquiler']->id, $this->acc['caja']->id, 150000);
        $this->postEntry('2026-01-10', $this->acc['suministros']->id, $this->acc['proveedores']->id, 80000);
        $this->postEntry('2026-01-15', $this->acc['caja']->id, $this->acc['ingresos']->id, 300000);
        $this->postEntry('2026-01-31', $this->acc['gasto_sum']->id, $this->acc['suministros']->id, 60000, 'ajuste');
    }

    private function postEntry(string $date, int $d, int $c, int $amt, string $type = 'normal'): void
    {
        $user = User::factory()->admin()->create();
        $period = AccountingPeriod::resolveOpenForDate($date);
        app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => $date, 'accounting_period_id' => $period->id,
            'entry_type' => $type, 'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $d, 'debit_amount' => $amt],
            ['chart_of_account_id' => $c, 'credit_amount' => $amt],
        ]);
    }

    public function test_worksheet_utility_and_balance(): void
    {
        $period = AccountingPeriod::resolveOpenForDate('2026-01-31');
        $svc = app(WorksheetService::class);
        $svc->generate($period);
        $data = $svc->present($period);

        // Utilidad = ingresos 3.000 - gastos 2.100 = 900 (centavos 90.000)
        $this->assertEquals(90000, $data['utilidad']);
        $this->assertTrue($data['cuadra']);

        $sum = collect($data['filas'])->firstWhere('code', '1.1.2.01');
        $this->assertEquals(20000, $sum['saldo_aj_debe']); // 800 - 600 = 200
        $this->assertEquals(60000, $sum['ajuste_credito']);
        $this->assertEquals(80000, $sum['mov_debito']);
    }
}
