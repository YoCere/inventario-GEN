<?php

namespace Tests\Feature\Accounting;

use App\Enums\JournalEntryType;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntryTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
    }

    public function test_default_entry_type_is_normal(): void
    {
        $user = User::factory()->admin()->create();
        $period = \App\Models\AccountingPeriod::resolveOpenForDate('2026-01-15');
        $coa = \App\Models\ChartOfAccount::where('allows_posting', true)->take(2)->get();

        $entry = app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-01-15',
            'accounting_period_id' => $period->id,
            'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $coa[0]->id, 'debit_amount' => 10000],
            ['chart_of_account_id' => $coa[1]->id, 'credit_amount' => 10000],
        ]);

        $this->assertEquals(JournalEntryType::Normal, $entry->entry_type);
    }

    public function test_ajuste_entry_type_persists(): void
    {
        $user = User::factory()->admin()->create();
        $period = \App\Models\AccountingPeriod::resolveOpenForDate('2026-01-15');
        $coa = \App\Models\ChartOfAccount::where('allows_posting', true)->take(2)->get();

        $entry = app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-01-15',
            'accounting_period_id' => $period->id,
            'entry_type' => JournalEntryType::Ajuste->value,
            'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $coa[0]->id, 'debit_amount' => 5000],
            ['chart_of_account_id' => $coa[1]->id, 'credit_amount' => 5000],
        ]);

        $this->assertEquals(JournalEntryType::Ajuste, $entry->entry_type);
        $this->assertEquals(1, \App\Models\JournalEntry::ajustes()->count());
    }

    public function test_enum_instance_entry_type_is_normalized(): void
    {
        $user = User::factory()->admin()->create();
        $period = \App\Models\AccountingPeriod::resolveOpenForDate('2026-01-15');
        $coa = \App\Models\ChartOfAccount::where('allows_posting', true)->take(2)->get();

        $entry = app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-01-15',
            'accounting_period_id' => $period->id,
            'entry_type' => JournalEntryType::Ajuste, // enum instance, not ->value
            'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $coa[0]->id, 'debit_amount' => 7000],
            ['chart_of_account_id' => $coa[1]->id, 'credit_amount' => 7000],
        ]);

        $this->assertEquals(JournalEntryType::Ajuste, $entry->entry_type);
    }
}
