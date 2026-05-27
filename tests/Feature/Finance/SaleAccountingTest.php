<?php

namespace Tests\Feature\Finance;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\User;
use App\Services\Accounting\SaleAccountingService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleAccountingTest extends TestCase
{
    use RefreshDatabase;

    private SaleAccountingService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);
        $this->service = app(SaleAccountingService::class);
        $this->user = User::factory()->admin()->create();
    }

    private function makeSale(array $overrides = []): Sale
    {
        return Sale::create(array_merge([
            'invoice_number'  => 'INV.260526.' . str_pad(rand(1, 9999), 6, '0', STR_PAD_LEFT),
            'created_by'      => $this->user->id,
            'sale_date'       => now(),
            'status'          => SaleStatus::COMPLETED,
            'payment_method'  => PaymentMethod::CASH,
            'source'          => 'pos',
            'subtotal'        => 10000,
            'total_discount'  => 0,
            'total'           => 10000,
            'cash_received'   => 10000,
            'change'          => 0,
            'global_discount' => 0,
        ], $overrides));
    }

    public function test_postCompletedSale_creates_balanced_journal_entry(): void
    {
        $sale = $this->makeSale();

        $entry = $this->service->postCompletedSale($sale, $this->user->id);

        $this->assertNotNull($entry);
        $this->assertEquals('posted', $entry->status->value);

        $debitTotal  = $entry->lines->sum('debit_amount');
        $creditTotal = $entry->lines->sum('credit_amount');
        $this->assertEquals($debitTotal, $creditTotal, 'Asiento debe cuadrar');
        $this->assertEquals(10000, $debitTotal);
    }

    public function test_postCompletedSale_is_idempotent(): void
    {
        $sale = $this->makeSale();

        $entry1 = $this->service->postCompletedSale($sale, $this->user->id);
        $entry2 = $this->service->postCompletedSale($sale, $this->user->id);

        $this->assertNotNull($entry1);
        $this->assertNull($entry2, 'Segunda llamada debe retornar null');

        $count = JournalEntry::where('source_id', $sale->id)
            ->where('source_type', Sale::class)
            ->where('status', 'posted')
            ->whereNull('reversed_entry_id')
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_reverseSaleEntry_creates_reversal_and_marks_original_reversed(): void
    {
        $sale  = $this->makeSale();
        $entry = $this->service->postCompletedSale($sale, $this->user->id);

        $reversal = $this->service->reverseSaleEntry($sale, $this->user->id, 'Test cancelacion');

        $this->assertNotNull($reversal);
        $this->assertEquals('posted', $reversal->status->value);
        $this->assertEquals($entry->id, $reversal->reversed_entry_id);
        $this->assertEquals('reversed', $entry->fresh()->status->value);
    }

    public function test_reverseSaleEntry_returns_null_when_no_posted_entry(): void
    {
        $sale = $this->makeSale(['status' => SaleStatus::PENDING]);

        $reversal = $this->service->reverseSaleEntry($sale, $this->user->id);

        $this->assertNull($reversal);
    }

    public function test_reversal_entry_is_balanced(): void
    {
        $sale    = $this->makeSale();
        $entry   = $this->service->postCompletedSale($sale, $this->user->id);
        $reversal = $this->service->reverseSaleEntry($sale, $this->user->id);

        $revDebit  = $reversal->lines->sum('debit_amount');
        $revCredit = $reversal->lines->sum('credit_amount');

        $this->assertEquals($revDebit, $revCredit, 'Reverso debe cuadrar');
        $this->assertEquals($entry->lines->sum('credit_amount'), $revDebit);
    }
}
