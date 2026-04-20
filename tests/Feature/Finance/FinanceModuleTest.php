<?php

namespace Tests\Feature\Finance;

use Tests\TestCase;
use App\Models\User;
use App\Models\ChartOfAccount;
use App\Models\AccountingPeriod;
use App\Services\Accounting\JournalEntryService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\AccountingPeriodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FinanceModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_pages_are_accessible_for_verified_user(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'role' => 'admin',
        ]);

        $this->actingAs($user)
            ->get(route('finance.index'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('finance.chart-of-accounts.index'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('finance.journal-entries.index'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('finance.statements.index'))
            ->assertOk();
    }

    public function test_journal_entry_can_be_reversed_correctly(): void
    {
        $this->seed([
            AccountingPeriodSeeder::class,
            ChartOfAccountSeeder::class,
        ]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'role' => 'admin',
        ]);

        $cash = ChartOfAccount::query()->where('code', '1.1.01')->firstOrFail();
        $sales = ChartOfAccount::query()->where('code', '4.1')->firstOrFail();

        $service = app(JournalEntryService::class);
        $period = AccountingPeriod::query()->firstOrFail();

        $entry = $service->createPostedEntry([
            'entry_date' => now()->toDateString(),
            'accounting_period_id' => $period->id,
            'description' => 'Prueba de asiento',
            'created_by' => $user->id,
            'posted_by' => $user->id,
        ], [
            [
                'chart_of_account_id' => $cash->id,
                'debit_amount' => 1000,
                'credit_amount' => 0,
            ],
            [
                'chart_of_account_id' => $sales->id,
                'debit_amount' => 0,
                'credit_amount' => 1000,
            ],
        ]);

        $reverse = $service->reverseEntry($entry, $user->id, 'Reverso de prueba');

        $this->assertEquals('reversed', $entry->fresh()->status->value);
        $this->assertEquals($entry->id, $reverse->reversed_entry_id);
        $this->assertCount(2, $reverse->lines);
        $this->assertEquals(1000, $reverse->lines[0]->credit_amount + $reverse->lines[1]->credit_amount);
        $this->assertEquals(1000, $reverse->lines[0]->debit_amount + $reverse->lines[1]->debit_amount);
    }
}
