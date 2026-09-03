<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountingPeriodStatus;
use App\Livewire\Accounting\GestionCierreWizard;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GestionCierreWizardTest extends TestCase
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

    public function test_admin_can_close_gestion_via_wizard(): void
    {
        $this->seedAll();
        Setting::set('company_entity_type', 'srl');

        // Capital 1.000.000 -> tope reserva 50% = 500.000
        $this->postEntry('1.1.01', '3.1', 100000000);
        // Ventas 100.000 -> utilidad antes impuestos 100.000 -> iue 25.000, reserva 5% = 3.750
        $this->postEntry('1.1.01', '4.1', 10000000);

        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(GestionCierreWizard::class)
            ->set('year', 2026)
            ->call('cerrar')
            ->assertHasNoErrors();

        $this->assertTrue(JournalEntry::where('entry_type', 'cierre')->exists());
    }

    public function test_non_admin_cannot_access_closing_route(): void
    {
        $this->seedAll();

        $this->actingAs(User::factory()->create());

        // La ruta está gateada por middleware('admin'); un no-admin recibe 403.
        $this->get(route('accounting.closing.index'))->assertForbidden();
    }
}
