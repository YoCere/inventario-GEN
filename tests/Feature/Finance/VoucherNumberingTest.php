<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountingPeriodStatus;
use App\Enums\VoucherType;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoucherNumberingTest extends TestCase
{
    use RefreshDatabase;

    private JournalEntryService $service;
    private User $user;
    private AccountingPeriod $period1;
    private AccountingPeriod $period2;
    private int $debitAccountId;
    private int $creditAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ChartOfAccountSeeder::class, SettingSeeder::class]);

        $this->service = app(JournalEntryService::class);
        $this->user    = User::factory()->admin()->create();

        // Period 1: January 2026
        $this->period1 = AccountingPeriod::create([
            'name'       => 'Enero 2026',
            'start_date' => '2026-01-01',
            'end_date'   => '2026-01-31',
            'status'     => AccountingPeriodStatus::Open->value,
        ]);

        // Period 2: February 2026 (separate period for restart test)
        $this->period2 = AccountingPeriod::create([
            'name'       => 'Febrero 2026',
            'start_date' => '2026-02-01',
            'end_date'   => '2026-02-28',
            'status'     => AccountingPeriodStatus::Open->value,
        ]);

        // Use real chart accounts from seeder: 1.1.01 (debit) / 4.1 (credit)
        $this->debitAccountId  = ChartOfAccount::where('code', '1.1.01')->value('id');
        $this->creditAccountId = ChartOfAccount::where('code', '4.1')->value('id');
    }

    private function makePayload(AccountingPeriod $period, string $voucherType, string $date): array
    {
        return [
            'entry_date'           => $date,
            'accounting_period_id' => $period->id,
            'description'          => 'Test entry',
            'voucher_type'         => $voucherType,
            'created_by'           => $this->user->id,
            'posted_by'            => $this->user->id,
        ];
    }

    private function balancedLines(): array
    {
        return [
            [
                'chart_of_account_id' => $this->debitAccountId,
                'debit_amount'        => 1000,
                'credit_amount'       => 0,
            ],
            [
                'chart_of_account_id' => $this->creditAccountId,
                'debit_amount'        => 0,
                'credit_amount'       => 1000,
            ],
        ];
    }

    public function test_first_ingreso_in_period_gets_voucher_number_1(): void
    {
        $entry = $this->service->createPostedEntry(
            $this->makePayload($this->period1, VoucherType::Ingreso->value, '2026-01-10'),
            $this->balancedLines()
        );

        $fresh = $entry->fresh();
        $this->assertEquals(VoucherType::Ingreso->value, $fresh->voucher_type->value);
        $this->assertEquals(1, $fresh->voucher_number);
    }

    public function test_second_ingreso_in_same_period_gets_voucher_number_2(): void
    {
        $this->service->createPostedEntry(
            $this->makePayload($this->period1, VoucherType::Ingreso->value, '2026-01-10'),
            $this->balancedLines()
        );

        $entry2 = $this->service->createPostedEntry(
            $this->makePayload($this->period1, VoucherType::Ingreso->value, '2026-01-11'),
            $this->balancedLines()
        );

        $this->assertEquals(2, $entry2->fresh()->voucher_number);
    }

    public function test_egreso_counter_is_independent_from_ingreso(): void
    {
        // Create two ingreso entries first
        $this->service->createPostedEntry(
            $this->makePayload($this->period1, VoucherType::Ingreso->value, '2026-01-10'),
            $this->balancedLines()
        );
        $this->service->createPostedEntry(
            $this->makePayload($this->period1, VoucherType::Ingreso->value, '2026-01-11'),
            $this->balancedLines()
        );

        // First egreso in same period should be #1, not #3
        $egreso = $this->service->createPostedEntry(
            $this->makePayload($this->period1, VoucherType::Egreso->value, '2026-01-12'),
            $this->balancedLines()
        );

        $fresh = $egreso->fresh();
        $this->assertEquals(VoucherType::Egreso->value, $fresh->voucher_type->value);
        $this->assertEquals(1, $fresh->voucher_number);
    }

    public function test_voucher_numbering_restarts_at_1_for_new_period(): void
    {
        // Create an ingreso in period 1
        $this->service->createPostedEntry(
            $this->makePayload($this->period1, VoucherType::Ingreso->value, '2026-01-10'),
            $this->balancedLines()
        );

        // Create an ingreso in period 2 — should restart at 1
        $entryPeriod2 = $this->service->createPostedEntry(
            $this->makePayload($this->period2, VoucherType::Ingreso->value, '2026-02-05'),
            $this->balancedLines()
        );

        $this->assertEquals(1, $entryPeriod2->fresh()->voucher_number);
    }

    public function test_reverse_entry_gets_traspaso_voucher_type(): void
    {
        $original = $this->service->createPostedEntry(
            $this->makePayload($this->period1, VoucherType::Ingreso->value, '2026-01-10'),
            $this->balancedLines()
        );

        $reversal = $this->service->reverseEntry($original, $this->user->id);

        $fresh = $reversal->fresh();
        $this->assertNotNull($fresh->voucher_type);
        $this->assertEquals(VoucherType::Traspaso->value, $fresh->voucher_type->value);
        $this->assertNotNull($fresh->voucher_number);
    }

    public function test_default_voucher_type_is_traspaso_when_not_specified(): void
    {
        $payload = [
            'entry_date'           => '2026-01-10',
            'accounting_period_id' => $this->period1->id,
            'description'          => 'Entry without explicit voucher_type',
            'created_by'           => $this->user->id,
            'posted_by'            => $this->user->id,
            // intentionally omit voucher_type
        ];

        $entry = $this->service->createPostedEntry($payload, $this->balancedLines());

        $fresh = $entry->fresh();
        $this->assertEquals(VoucherType::Traspaso->value, $fresh->voucher_type->value);
        $this->assertEquals(1, $fresh->voucher_number);
    }
}
