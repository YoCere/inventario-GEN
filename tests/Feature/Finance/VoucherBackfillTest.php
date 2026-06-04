<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountingPeriodStatus;
use App\Enums\JournalEntryStatus;
use App\Models\User;
use App\Support\VoucherBackfiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests the VoucherBackfiller::run() helper that backfills voucher_type and
 * voucher_number on journal_entries rows that were created before DB1
 * (i.e., rows where voucher_number IS NULL).
 *
 * We insert raw rows via DB::table() to simulate the "pre-DB1" state, then
 * invoke the backfiller directly so the test exercises the exact same logic
 * that the migration calls.
 */
class VoucherBackfillTest extends TestCase
{
    use RefreshDatabase;

    private int $userId;
    private int $periodId;

    protected function setUp(): void
    {
        parent::setUp();

        // A user is required for the created_by FK.
        $this->userId = User::factory()->create()->id;

        // One accounting period for all test rows.
        $this->periodId = DB::table('accounting_periods')->insertGetId([
            'name'       => 'Backfill Test Period',
            'start_date' => '2025-01-01',
            'end_date'   => '2025-12-31',
            'status'     => AccountingPeriodStatus::Open->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Insert a raw journal_entry row with voucher_type/voucher_number = NULL
     * (as they would appear before the backfill).
     */
    private function insertEntry(string $entryNumber, string $entryDate, ?string $sourceType): void
    {
        DB::table('journal_entries')->insert([
            'entry_number'         => $entryNumber,
            'entry_date'           => $entryDate,
            'accounting_period_id' => $this->periodId,
            'source_type'          => $sourceType,
            'source_id'            => null,
            'status'               => JournalEntryStatus::Posted->value,
            'voucher_type'         => null,
            'voucher_number'       => null,
            'created_by'           => $this->userId,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
    }

    public function test_sale_entries_become_ingreso(): void
    {
        $this->insertEntry('JE-001', '2025-03-01', 'App\\Models\\Sale');

        VoucherBackfiller::run();

        $row = DB::table('journal_entries')->where('entry_number', 'JE-001')->first();
        $this->assertEquals('ingreso', $row->voucher_type);
        $this->assertEquals(1, $row->voucher_number);
    }

    public function test_purchase_entries_become_egreso(): void
    {
        $this->insertEntry('JE-002', '2025-03-02', 'App\\Models\\Purchase');

        VoucherBackfiller::run();

        $row = DB::table('journal_entries')->where('entry_number', 'JE-002')->first();
        $this->assertEquals('egreso', $row->voucher_type);
        $this->assertEquals(1, $row->voucher_number);
    }

    public function test_payroll_entries_become_traspaso(): void
    {
        $this->insertEntry('JE-003', '2025-03-03', 'App\\Models\\PayrollSheet');

        VoucherBackfiller::run();

        $row = DB::table('journal_entries')->where('entry_number', 'JE-003')->first();
        $this->assertEquals('traspaso', $row->voucher_type);
        $this->assertEquals(1, $row->voucher_number);
    }

    public function test_null_source_type_becomes_traspaso(): void
    {
        $this->insertEntry('JE-004', '2025-03-04', null);

        VoucherBackfiller::run();

        $row = DB::table('journal_entries')->where('entry_number', 'JE-004')->first();
        $this->assertEquals('traspaso', $row->voucher_type);
        $this->assertEquals(1, $row->voucher_number);
    }

    public function test_mixed_entries_are_numbered_independently_per_type(): void
    {
        // Insert in a specific order to verify sequential numbering by entry_date then id.
        // Entry dates chosen to control the ordering within each type.
        $this->insertEntry('JE-S1', '2025-02-01', 'App\\Models\\Sale');
        $this->insertEntry('JE-P1', '2025-02-02', 'App\\Models\\Purchase');
        $this->insertEntry('JE-N1', '2025-02-03', null);           // traspaso #1
        $this->insertEntry('JE-N2', '2025-02-04', null);           // traspaso #2 (later date)

        VoucherBackfiller::run();

        $sale     = DB::table('journal_entries')->where('entry_number', 'JE-S1')->first();
        $purchase = DB::table('journal_entries')->where('entry_number', 'JE-P1')->first();
        $null1    = DB::table('journal_entries')->where('entry_number', 'JE-N1')->first();
        $null2    = DB::table('journal_entries')->where('entry_number', 'JE-N2')->first();

        // Sale → ingreso #1
        $this->assertEquals('ingreso', $sale->voucher_type);
        $this->assertEquals(1, (int) $sale->voucher_number);

        // Purchase → egreso #1 (independent counter)
        $this->assertEquals('egreso', $purchase->voucher_type);
        $this->assertEquals(1, (int) $purchase->voucher_number);

        // Null entries → traspaso, ordered by entry_date
        $this->assertEquals('traspaso', $null1->voucher_type);
        $this->assertEquals(1, (int) $null1->voucher_number);

        $this->assertEquals('traspaso', $null2->voucher_type);
        $this->assertEquals(2, (int) $null2->voucher_number);
    }

    public function test_no_row_is_left_with_null_voucher_number(): void
    {
        $this->insertEntry('JE-A', '2025-05-01', 'App\\Models\\Sale');
        $this->insertEntry('JE-B', '2025-05-02', 'App\\Models\\Purchase');
        $this->insertEntry('JE-C', '2025-05-03', null);

        VoucherBackfiller::run();

        $nullCount = DB::table('journal_entries')
            ->where('accounting_period_id', $this->periodId)
            ->whereNull('voucher_number')
            ->count();

        $this->assertEquals(0, $nullCount);
    }

    public function test_backfill_is_idempotent(): void
    {
        $this->insertEntry('JE-X1', '2025-06-01', 'App\\Models\\Sale');
        $this->insertEntry('JE-X2', '2025-06-02', 'App\\Models\\Purchase');
        $this->insertEntry('JE-X3', '2025-06-03', null);

        // First run assigns numbers.
        VoucherBackfiller::run();

        $before = DB::table('journal_entries')
            ->where('accounting_period_id', $this->periodId)
            ->orderBy('id')
            ->get(['entry_number', 'voucher_type', 'voucher_number'])
            ->toArray();

        // Second run must not change anything.
        VoucherBackfiller::run();

        $after = DB::table('journal_entries')
            ->where('accounting_period_id', $this->periodId)
            ->orderBy('id')
            ->get(['entry_number', 'voucher_type', 'voucher_number'])
            ->toArray();

        $this->assertEquals($before, $after, 'Running the backfill twice must produce the same result.');

        // No nulls after second run either.
        $nullCount = DB::table('journal_entries')
            ->where('accounting_period_id', $this->periodId)
            ->whereNull('voucher_number')
            ->count();

        $this->assertEquals(0, $nullCount);
    }
}
