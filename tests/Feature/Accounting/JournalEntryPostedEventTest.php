<?php

namespace Tests\Feature\Accounting;

use App\Events\JournalEntryPosted;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class JournalEntryPostedEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_posting_an_entry_dispatches_event(): void
    {
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        Event::fake([JournalEntryPosted::class]);

        $user = User::factory()->admin()->create();
        $period = AccountingPeriod::resolveOpenForDate('2026-01-15');
        $coa = ChartOfAccount::where('allows_posting', true)->take(2)->get();

        app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-01-15',
            'accounting_period_id' => $period->id,
            'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $coa[0]->id, 'debit_amount' => 10000],
            ['chart_of_account_id' => $coa[1]->id, 'credit_amount' => 10000],
        ]);

        Event::assertDispatched(JournalEntryPosted::class);
    }
}
