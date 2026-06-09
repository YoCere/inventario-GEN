<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriod;
use App\Models\Budget;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\Accounting\BudgetProjectionService;
use App\Services\Accounting\JournalEntryService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_lines_from_actuals(): void
    {
        $this->seed([ChartOfAccountSeeder::class, SettingSeeder::class]);
        $user = User::factory()->admin()->create();

        $period = AccountingPeriod::create([
            'name'       => '2025',
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
            ['chart_of_account_id' => $caja->id,   'debit_amount'  => 1000000],
            ['chart_of_account_id' => $ventas->id, 'credit_amount' => 1000000],
        ]);

        $budget = Budget::create([
            'name'       => 'Plan',
            'base_from'  => '2025-01-01',
            'base_to'    => '2025-12-31',
            'years'      => 5,
            'growth_pct' => 3,
        ]);

        app(BudgetProjectionService::class)->seedFromActuals($budget);

        $line = $budget->lines()->where('chart_of_account_code', '4.1')->first();
        $this->assertNotNull($line);
        $this->assertEquals('income', $line->line_type);
        $this->assertEquals(1000000, $line->base_amount);
    }
}
