<?php

namespace Tests\Feature\Accounting;

use App\DTOs\OpeningBalanceData;
use App\Enums\AccountingPeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\LedgerBalanceService;
use App\Services\Accounting\OpeningBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpeningReportsVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function postApertura(): void
    {
        ChartOfAccount::create(['code' => '1.1.01', 'name' => 'Caja', 'level' => 3, 'account_type' => 'asset', 'normal_balance' => 'debit', 'allows_posting' => true, 'is_active' => true]);
        ChartOfAccount::create(['code' => '3.1', 'name' => 'Capital', 'level' => 2, 'account_type' => 'equity', 'normal_balance' => 'credit', 'allows_posting' => true, 'is_active' => true]);
        AccountingPeriod::create(['name' => 'Gestion 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Closed]);

        $user = User::factory()->create();
        app(OpeningBalanceService::class)->post(
            OpeningBalanceData::fromArray(['cash' => 18000000, 'capital' => 18000000]),
            '2026-01-01',
            $user->id,
        );
    }

    public function test_opening_shows_in_base_balances_not_only_adjusted(): void
    {
        $this->postApertura();

        // El default (sin ajustes) DEBE incluir la apertura — antes quedaba oculta.
        $base = app(LedgerBalanceService::class)->balancesAt('2026-01-01', includeAdjustments: false);
        $caja = $base->firstWhere('code', '1.1.01');

        $this->assertNotNull($caja, 'La apertura debe aparecer en el balance base (sin ajustes).');
        $this->assertSame(18000000, (int) $caja->debit);
    }

    public function test_movimientos_scope_includes_apertura(): void
    {
        $this->postApertura();

        $this->assertSame(1, JournalEntry::query()->movimientos()->where('entry_type', 'apertura')->count());
    }
}
