<?php

namespace Tests\Feature\Sales;

use App\DTOs\SaleData;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Exceptions\SaleException;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SaleService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleServiceTest extends TestCase
{
    use RefreshDatabase;

    private SaleService $service;
    private User $seller;
    private Product $product;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            AccountingPeriodSeeder::class,
            ChartOfAccountSeeder::class,
            SettingSeeder::class,
        ]);

        $this->service = app(SaleService::class);
        $this->seller = User::factory()->staff()->create();

        $warehouse = Warehouse::create(['name' => 'Almacén', 'code' => 'ALM']);
        $this->location = Location::create(['name' => 'Estante', 'code' => 'EST', 'warehouse_id' => $warehouse->id]);

        $this->product = Product::factory()->create([
            'selling_price'  => 10000,
            'purchase_price' => 6000,
            'quantity'       => 0,
        ]);

        ProductStock::create([
            'product_id'  => $this->product->id,
            'location_id' => $this->location->id,
            'quantity'    => 20,
        ]);
        $this->product->update(['quantity' => 20]);
    }

    private function makeSaleData(array $overrides = []): SaleData
    {
        return SaleData::fromArray(array_merge([
            'customer_id'     => null,
            'buyer_name'      => null,
            'buyer_phone'     => null,
            'created_by'      => $this->seller->id,
            'sale_date'       => now()->toDateTimeString(),
            'status'          => SaleStatus::COMPLETED->value,
            'payment_method'  => PaymentMethod::CASH->value,
            'source'          => 'pos',
            'notes'           => null,
            'cash_received'   => 20000,
            'change'          => 0,
            'global_discount' => 0,
            'items'           => [
                [
                    'product_id' => $this->product->id,
                    'quantity'   => 2,
                    'unit_price' => 10000,
                    'discount'   => 0,
                ],
            ],
        ], $overrides));
    }

    public function test_createSale_deducts_stock_and_creates_journal_entry(): void
    {
        $sale = $this->service->createSale($this->makeSaleData());

        $this->assertEquals(SaleStatus::COMPLETED, $sale->status);
        $this->assertEquals(20000, $sale->total);
        $this->assertEquals(18, $this->product->fresh()->quantity);
        $this->assertDatabaseHas('journal_entries', ['source_id' => $sale->id, 'status' => 'posted']);
    }

    public function test_createSale_throws_when_insufficient_stock(): void
    {
        $this->expectException(SaleException::class);

        $this->service->createSale($this->makeSaleData([
            'items' => [['product_id' => $this->product->id, 'quantity' => 25, 'unit_price' => 10000, 'discount' => 0]],
        ]));
    }

    public function test_createSale_throws_when_item_discount_exceeds_unit_price(): void
    {
        $this->expectException(SaleException::class);

        $this->service->createSale($this->makeSaleData([
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 10000, 'discount' => 15000]],
        ]));
    }

    public function test_createSale_throws_when_global_discount_exceeds_subtotal(): void
    {
        $this->expectException(SaleException::class);

        $this->service->createSale($this->makeSaleData([
            'global_discount' => 99999999,
            'cash_received'   => 99999999,
        ]));
    }

    public function test_cancelSale_restores_stock_and_reverses_journal_entry(): void
    {
        $sale = $this->service->createSale($this->makeSaleData());
        $this->assertEquals(18, $this->product->fresh()->quantity);

        $this->service->cancelSale($sale, 'Test cancellation');

        $this->assertEquals(SaleStatus::CANCELLED, $sale->fresh()->status);
        $this->assertEquals(20, $this->product->fresh()->quantity);
        $this->assertDatabaseHas('journal_entries', [
            'source_id' => $sale->id,
            'status'    => 'reversed',
        ]);
    }

    public function test_cancelSale_throws_when_already_cancelled(): void
    {
        $sale = $this->service->createSale($this->makeSaleData());
        $this->service->cancelSale($sale);

        $this->expectException(SaleException::class);

        $this->service->cancelSale($sale->fresh());
    }

    public function test_completeSale_throws_when_cash_received_insufficient(): void
    {
        $pendingData = $this->makeSaleData([
            'status'        => SaleStatus::PENDING->value,
            'cash_received' => 0,
        ]);
        $sale = $this->service->createSale($pendingData);

        $this->expectException(SaleException::class);

        $this->service->completeSale($sale, ['cash_received' => 1000]);
    }

    public function test_completeSale_creates_journal_entry_when_completing_pending_sale(): void
    {
        $pendingData = $this->makeSaleData([
            'status'        => SaleStatus::PENDING->value,
            'cash_received' => 0,
        ]);
        $sale = $this->service->createSale($pendingData);
        $this->assertDatabaseMissing('journal_entries', ['source_id' => $sale->id]);

        $this->service->completeSale($sale, ['cash_received' => 20000]);

        $this->assertEquals(SaleStatus::COMPLETED, $sale->fresh()->status);
        $this->assertDatabaseHas('journal_entries', ['source_id' => $sale->id, 'status' => 'posted']);
    }

    public function test_accounting_entry_is_not_duplicated_on_double_post(): void
    {
        $sale = $this->service->createSale($this->makeSaleData());

        app(\App\Services\Accounting\SaleAccountingService::class)
            ->postCompletedSale($sale->fresh(['items']), $this->seller->id);

        $count = \App\Models\JournalEntry::where('source_id', $sale->id)
            ->where('source_type', \App\Models\Sale::class)
            ->where('status', 'posted')
            ->whereNull('reversed_entry_id')
            ->count();

        $this->assertEquals(1, $count);
    }
}
