<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountingPeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\GestionCierreService;
use App\Services\Accounting\JournalEntryService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class GestionCierreCloseTest extends TestCase
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
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->acc('1.1.01', 'Caja', 'asset', 'debit');
        $this->acc('4.1', 'Ventas', 'income', 'credit');
        $this->acc('3.1', 'Capital', 'equity', 'credit');
        AccountingPeriod::create(['name' => 'G2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Open]);
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

    public function test_close_postea_asiento_de_cierre_y_es_idempotente(): void
    {
        $this->seedAll();
        Setting::set('company_entity_type', 'srl');

        // Capital 1.000.000 -> tope reserva 50% = 500.000
        $this->postEntry('1.1.01', '3.1', 100000000);
        // Ventas 100.000 -> utilidad antes impuestos 100.000 -> iue 25.000, despues 75.000, reserva 5% = 3.750
        $this->postEntry('1.1.01', '4.1', 10000000);

        $user = User::factory()->create();
        $entry = app(GestionCierreService::class)->close(2026, $user->id);

        $this->assertNotNull($entry);
        $this->assertSame('cierre', $entry->entry_type->value);
        $this->assertSame((int) $entry->lines->sum('debit_amount'), (int) $entry->lines->sum('credit_amount'));
        $this->assertCount(4, $entry->lines);

        $this->expectException(RuntimeException::class);
        app(GestionCierreService::class)->close(2026, $user->id);
    }

    public function test_close_con_perdida_no_genera_asiento(): void
    {
        $this->seedAll();
        $this->acc('5.1', 'Costo de Ventas', 'cost', 'debit');
        $this->acc('1.1.04', 'Inventario', 'asset', 'debit');

        // Sin ventas, con costo alto -> perdida -> nada que provisionar.
        $this->postEntry('5.1', '1.1.04', 6000000);

        $user = User::factory()->create();
        $entry = app(GestionCierreService::class)->close(2026, $user->id);

        $this->assertNull($entry);
    }

    public function test_close_unipersonal_no_genera_linea_de_reserva(): void
    {
        $this->seedAll();
        Setting::set('company_entity_type', 'unipersonal');

        $this->postEntry('1.1.01', '4.1', 10000000);

        $user = User::factory()->create();
        $entry = app(GestionCierreService::class)->close(2026, $user->id);

        $this->assertNotNull($entry);
        $this->assertCount(2, $entry->lines);
    }
}
