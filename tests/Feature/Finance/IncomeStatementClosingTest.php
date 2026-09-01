<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountingPeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use App\Services\FinancialStatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomeStatementClosingTest extends TestCase
{
    use RefreshDatabase;

    private function acc(string $code, string $name, string $type, string $nb): void
    {
        ChartOfAccount::updateOrCreate(['code' => $code], [
            'name' => $name, 'level' => strlen($code) > 3 ? 3 : 2, 'account_type' => $type,
            'normal_balance' => $nb, 'allows_posting' => true, 'is_active' => true,
        ]);
    }

    private function seedAll(): void
    {
        $this->acc('1.1.01', 'Caja', 'asset', 'debit');
        $this->acc('1.1.04', 'Inventario', 'asset', 'debit');
        $this->acc('3.1', 'Capital', 'equity', 'credit');
        $this->acc('4.1', 'Ventas', 'income', 'credit');
        $this->acc('5.1', 'Costo de Ventas', 'cost', 'debit');
        $this->acc('6.1', 'Gastos', 'expense', 'debit');
        AccountingPeriod::create(['name' => 'Gestion', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Open]);
    }

    private function postEntry(string $debitCode, string $creditCode, int $cents): void
    {
        $user = User::factory()->create();
        $period = AccountingPeriod::first();
        app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-06-01', 'accounting_period_id' => $period->id, 'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => ChartOfAccount::where('code', $debitCode)->value('id'), 'debit_amount' => $cents],
            ['chart_of_account_id' => ChartOfAccount::where('code', $creditCode)->value('id'), 'credit_amount' => $cents],
        ]);
    }

    public function test_utilidad_positiva_calcula_iue_y_estructura(): void
    {
        $this->seedAll();
        $this->postEntry('1.1.01', '4.1', 10000000);
        $this->postEntry('5.1', '1.1.04', 6000000);
        $this->postEntry('6.1', '1.1.01', 1000000);

        $er = app(FinancialStatementService::class)->build('2026-01-01', '2026-12-31', withTaxes: true)['estado_resultados'];

        $this->assertSame(10000000, $er['ventas']);
        $this->assertSame(6000000, $er['cmv']['cmv_total']);
        $this->assertSame(4000000, $er['utilidad_bruta']);
        $this->assertSame(3000000, $er['utilidad_antes_impuestos']);
        $this->assertSame(750000, $er['iue']);
        $this->assertSame(2250000, $er['utilidad_despues_impuestos']);
        $this->assertSame(0, $er['reserva_legal']);
        $this->assertSame(2250000, $er['utilidad_gestion']);
    }

    public function test_perdida_no_calcula_iue(): void
    {
        $this->seedAll();
        $this->postEntry('1.1.01', '4.1', 1000000);
        $this->postEntry('5.1', '1.1.04', 6000000);

        $er = app(FinancialStatementService::class)->build('2026-01-01', '2026-12-31', withTaxes: true)['estado_resultados'];

        $this->assertTrue($er['utilidad_antes_impuestos'] < 0);
        $this->assertSame(0, $er['iue']);
        $this->assertSame(0, $er['reserva_legal']);
    }

    public function test_srl_calcula_reserva_legal_con_tope(): void
    {
        $this->seedAll();
        \App\Models\Setting::set('company_entity_type', 'srl');
        $this->postEntry('1.1.01', '3.1', 100000000);
        $this->postEntry('1.1.01', '4.1', 10000000);

        $er = app(FinancialStatementService::class)->build('2026-01-01', '2026-12-31', withTaxes: true)['estado_resultados'];

        $this->assertSame(375000, $er['reserva_legal']);
        $this->assertSame($er['utilidad_despues_impuestos'] - 375000, $er['utilidad_gestion']);
    }

    public function test_reserva_legal_respeta_tope_cercano(): void
    {
        $this->seedAll();
        $this->acc('3.4', 'Reserva Legal', 'equity', 'credit');
        \App\Models\Setting::set('company_entity_type', 'srl');

        // Capital 100.000 -> tope 50% = 50.000. Reserva ya acumulada = 49.900 -> margen 100.
        $this->postEntry('1.1.01', '3.1', 10000000);
        $this->postEntry('1.1.01', '3.4', 4990000);
        // Utilidad grande: 5% daría más que el margen -> se clampea al margen (100).
        $this->postEntry('1.1.01', '4.1', 10000000);

        $er = app(FinancialStatementService::class)->build('2026-01-01', '2026-12-31', withTaxes: true)['estado_resultados'];

        $this->assertSame(10000, $er['reserva_legal']); // = margen (50.000 - 49.900), no el 5%
    }
}
