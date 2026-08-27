<?php

namespace Tests\Feature\Accounting;

use App\DTOs\OpeningBalanceData;
use App\Enums\AccountingPeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\Accounting\LedgerBalanceService;
use App\Services\Accounting\OpeningBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class OpeningBalancePostTest extends TestCase
{
    use RefreshDatabase;

    private function seedAccounts(): void
    {
        foreach ([
            ['1.1.01', 'Caja', 'asset', 'debit'], ['1.1.03', 'CxC', 'asset', 'debit'],
            ['1.1.04', 'Inventario', 'asset', 'debit'], ['1.2.01', 'PPE', 'asset', 'debit'],
            ['2.1.01', 'CxP', 'liability', 'credit'], ['3.1', 'Capital', 'equity', 'credit'],
        ] as [$code, $name, $type, $nb]) {
            ChartOfAccount::create(['code' => $code, 'name' => $name, 'level' => 3, 'account_type' => $type, 'normal_balance' => $nb, 'allows_posting' => true, 'is_active' => true]);
        }
        AccountingPeriod::create(['name' => 'Gestion 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Closed]);
    }

    private function data(): OpeningBalanceData
    {
        return OpeningBalanceData::fromArray([
            'cash' => 18000000, 'receivable' => 1500000, 'inventory' => 9050000, 'ppe' => 3500000,
            'payable' => 1000000, 'capital' => 31050000,
        ]);
    }

    public function test_posts_balanced_opening_to_closed_period(): void
    {
        $this->seedAccounts();
        $user = User::factory()->create();

        $entry = app(OpeningBalanceService::class)->post($this->data(), '2026-01-01', $user->id);

        $this->assertSame('apertura', $entry->entry_type->value);
        $this->assertSame(32050000, (int) $entry->lines->sum('debit_amount'));
        $this->assertSame(32050000, (int) $entry->lines->sum('credit_amount'));
    }

    public function test_second_opening_same_gestion_throws(): void
    {
        $this->seedAccounts();
        $user = User::factory()->create();
        $service = app(OpeningBalanceService::class);
        $service->post($this->data(), '2026-01-01', $user->id);

        $this->expectException(RuntimeException::class);
        $service->post($this->data(), '2026-01-05', $user->id);
    }

    public function test_negative_capital_rejected(): void
    {
        $this->seedAccounts();
        $user = User::factory()->create();
        $bad = OpeningBalanceData::fromArray(['cash' => 100000, 'payable' => 500000, 'capital' => -400000]);

        $this->expectException(RuntimeException::class);
        app(OpeningBalanceService::class)->post($bad, '2026-01-01', $user->id);
    }
}
