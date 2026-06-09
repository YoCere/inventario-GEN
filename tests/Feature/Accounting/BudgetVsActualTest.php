<?php

namespace Tests\Feature\Accounting;

use App\Models\Budget;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\Accounting\BudgetProjectionService;
use App\Services\Accounting\JournalEntryService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetVsActualTest extends TestCase
{
    use RefreshDatabase;

    public function test_year_one_actual_vs_projected(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        $user = User::factory()->admin()->create();

        // Crear el periodo 2025 directamente (el seeder crea 2026). Mirror BudgetSeedTest.
        $period = \App\Models\AccountingPeriod::create([
            'name'       => 'Gestion 2025',
            'start_date' => '2025-01-01',
            'end_date'   => '2025-12-31',
            'status'     => 'open',
        ]);
        $caja   = ChartOfAccount::where('code', '1.1.01')->first();
        $ventas = ChartOfAccount::where('code', '4.1')->first();

        app(JournalEntryService::class)->createPostedEntry([
            'entry_date'           => '2025-06-15',
            'accounting_period_id' => $period->id,
            'created_by'           => $user->id,
        ], [
            ['chart_of_account_id' => $caja->id,   'debit_amount'  => 900000],
            ['chart_of_account_id' => $ventas->id, 'credit_amount' => 900000],
        ]);

        $b = Budget::create([
            'name'       => 'P',
            'base_from'  => '2025-01-01',
            'base_to'    => '2025-12-31',
            'years'      => 5,
            'growth_pct' => 3,
        ]);
        $b->lines()->create([
            'chart_of_account_code' => '4.1',
            'name'                  => 'Ventas',
            'line_type'             => 'income',
            'base_amount'           => 1000000,
        ]);

        $cmp = app(BudgetProjectionService::class)->budgetVsActual($b->fresh('lines'), 1);

        $this->assertEquals(1000000, $cmp['totals']['projected_income']);
        $this->assertEquals(900000,  $cmp['totals']['actual_income']);
        $this->assertEquals(-100000, $cmp['totals']['variance_income']);
    }
}
