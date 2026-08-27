<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountingPeriodStatus;
use App\Livewire\Accounting\OpeningBalanceWizard;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OpeningBalanceWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_post_opening_via_wizard(): void
    {
        foreach ([['1.1.01', 'Caja', 'asset', 'debit'], ['3.1', 'Capital', 'equity', 'credit']] as [$c, $n, $t, $nb]) {
            ChartOfAccount::create(['code' => $c, 'name' => $n, 'level' => 3, 'account_type' => $t, 'normal_balance' => $nb, 'allows_posting' => true, 'is_active' => true]);
        }
        AccountingPeriod::create(['name' => 'Gestion 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Closed]);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(OpeningBalanceWizard::class)
            ->set('date', '2026-01-01')
            ->set('cash', 1800)
            ->set('capital', 1800)
            ->call('save')
            ->assertHasNoErrors();

        $entry = JournalEntry::where('entry_type', 'apertura')->firstOrFail();
        $this->assertSame(180000, (int) $entry->lines->sum('debit_amount'));
    }
}
