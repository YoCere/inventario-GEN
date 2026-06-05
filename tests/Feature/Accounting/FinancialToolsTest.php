<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use App\Services\Agent\AgentContext;
use App\Services\Agent\Tools\GetIncomeAndExpensesTool;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_income_expenses_tool_returns_structured_data(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        $ingresos = ChartOfAccount::firstOrCreate(['code' => '4.1.1.01'],
            ['name' => 'Ingresos', 'account_type' => 'income', 'normal_balance' => 'credit', 'allows_posting' => true, 'is_active' => true, 'level' => 4]);
        $caja = ChartOfAccount::firstOrCreate(['code' => '1.1.1.01'],
            ['name' => 'Caja', 'account_type' => 'asset', 'normal_balance' => 'debit', 'allows_posting' => true, 'is_active' => true, 'level' => 4]);
        $user = User::factory()->admin()->create();
        $period = AccountingPeriod::resolveOpenForDate('2026-01-15');
        app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-01-15', 'accounting_period_id' => $period->id, 'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $caja->id, 'debit_amount' => 300000],
            ['chart_of_account_id' => $ingresos->id, 'credit_amount' => 300000],
        ]);

        $tool = app(GetIncomeAndExpensesTool::class);
        $ctx = new AgentContext($user, 'test-chat');
        $out = $tool->execute(['from' => '2026-01-01', 'to' => '2026-01-31'], $ctx);

        $this->assertEquals(3000.0, $out['resumen']['ingresos']);
    }

    public function test_tool_rejects_bad_date(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        $user = User::factory()->admin()->create();
        $out = app(GetIncomeAndExpensesTool::class)->execute(['from' => 'ayer', 'to' => '2026-01-31'], new AgentContext($user, 'test-chat'));
        $this->assertArrayHasKey('error', $out);
    }

    public function test_tool_blocks_non_admin_user(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        $nonAdmin = User::factory()->create();

        $out = app(GetIncomeAndExpensesTool::class)
            ->execute(['from' => '2026-01-01', 'to' => '2026-01-31'], new AgentContext($nonAdmin, 'test-chat'));

        $this->assertArrayHasKey('error', $out);
        $this->assertStringContainsString('administrador', $out['error']);
    }

    public function test_tool_blocks_null_user(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);

        $out = app(GetIncomeAndExpensesTool::class)
            ->execute(['from' => '2026-01-01', 'to' => '2026-01-31'], new AgentContext(null, 'test-chat'));

        $this->assertArrayHasKey('error', $out);
    }
}
