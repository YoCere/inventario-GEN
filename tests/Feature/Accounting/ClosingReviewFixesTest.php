<?php

namespace Tests\Feature\Accounting;

use App\DTOs\OpeningBalanceData;
use App\Enums\AccountingPeriodStatus;
use App\Enums\JournalEntryType;
use App\Enums\VoucherType;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use App\Services\Accounting\OpeningBalanceService;
use App\Services\FinancialStatementService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClosingReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private function postEntry(string $debit, string $credit, int $cents, string $type = 'normal'): void
    {
        $user = User::factory()->create();
        $period = AccountingPeriod::first();
        app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-06-01', 'accounting_period_id' => $period->id,
            'entry_type' => $type, 'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => ChartOfAccount::where('code', $debit)->value('id'), 'debit_amount' => $cents],
            ['chart_of_account_id' => ChartOfAccount::where('code', $credit)->value('id'), 'credit_amount' => $cents],
        ], allowClosedPeriod: $type === 'cierre');
    }

    /** Fix #1: el Gasto IUE (6.8) no deflaciona la utilidad antes de impuestos. */
    public function test_gasto_iue_excluido_de_utilidad_antes_impuestos(): void
    {
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(SettingSeeder::class);
        AccountingPeriod::create(['name' => 'G2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Open]);

        $this->postEntry('1.1.01', '4.1', 10000000);  // ventas 100.000
        $this->postEntry('6.8', '2.1.13', 1000000);    // gasto IUE 10.000 posteado

        $er = app(FinancialStatementService::class)->build('2026-01-01', '2026-12-31', withTaxes: true)['estado_resultados'];

        // 6.8 NO resta: utilidad antes = 100.000 (no 90.000).
        $this->assertSame(10000000, $er['utilidad_antes_impuestos']);
    }

    /** Fix #2: la apertura puede postearse aunque ya exista un cierre en el mismo período. */
    public function test_apertura_no_bloqueada_por_cierre_existente(): void
    {
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(SettingSeeder::class);
        AccountingPeriod::create(['name' => 'G2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Open]);

        // Postear un cierre primero (anclado al período).
        $period = AccountingPeriod::first();
        $user = User::factory()->create();
        app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-12-31', 'accounting_period_id' => $period->id,
            'source_type' => AccountingPeriod::class, 'source_id' => $period->id,
            'entry_type' => JournalEntryType::Cierre->value, 'voucher_type' => VoucherType::Cierre->value,
            'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => ChartOfAccount::where('code', '6.8')->value('id'), 'debit_amount' => 100000],
            ['chart_of_account_id' => ChartOfAccount::where('code', '2.1.13')->value('id'), 'credit_amount' => 100000],
        ], allowClosedPeriod: true);

        // La apertura NO debe verse bloqueada por el cierre.
        $entry = app(OpeningBalanceService::class)->post(
            OpeningBalanceData::fromArray(['cash' => 5000000, 'capital' => 5000000]),
            '2026-01-01',
            $user->id,
        );

        $this->assertSame('apertura', $entry->entry_type->value);
    }
}
