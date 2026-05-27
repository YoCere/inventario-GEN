<?php

namespace Tests\Feature\Stock;

use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockService $service;
    private Location $location;
    private Location $location2;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(StockService::class);

        $warehouse = Warehouse::create(['name' => 'Almacén Principal', 'code' => 'ALM-01']);
        $this->location = Location::create(['name' => 'Estante A', 'code' => 'A', 'warehouse_id' => $warehouse->id]);
        $this->location2 = Location::create(['name' => 'Estante B', 'code' => 'B', 'warehouse_id' => $warehouse->id]);

        $this->product = Product::factory()->create(['quantity' => 0]);
    }

    public function test_pickFifo_returns_location_with_enough_stock(): void
    {
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 10]);

        $stock = $this->service->pickFifoLocationForSale($this->product->id, 5);

        $this->assertNotNull($stock);
        $this->assertEquals($this->location->id, $stock->location_id);
    }

    public function test_pickFifo_returns_null_when_no_single_location_has_enough(): void
    {
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 5]);
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location2->id, 'quantity' => 5]);

        $stock = $this->service->pickFifoLocationForSale($this->product->id, 10);

        $this->assertNull($stock);
    }

    public function test_pickFifo_returns_first_location_by_id_when_multiple_qualify(): void
    {
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 20]);
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location2->id, 'quantity' => 20]);

        $stock = $this->service->pickFifoLocationForSale($this->product->id, 10);

        $this->assertEquals($this->location->id, $stock->location_id);
    }

    public function test_decrementAt_reduces_stock_and_syncs_product_quantity(): void
    {
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 10]);
        $this->service->syncProductQuantity($this->product->id);

        $this->service->decrementAt($this->product->id, $this->location->id, 3);

        $stock = ProductStock::where('product_id', $this->product->id)
            ->where('location_id', $this->location->id)
            ->first();

        $this->assertEquals(7, $stock->quantity);
        $this->assertEquals(7, $this->product->fresh()->quantity);
    }

    public function test_decrementAt_throws_when_insufficient(): void
    {
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 2]);

        $this->expectException(\RuntimeException::class);

        $this->service->decrementAt($this->product->id, $this->location->id, 5);
    }

    public function test_decrementAt_throws_when_no_stock_row_exists(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->decrementAt($this->product->id, $this->location->id, 1);
    }

    public function test_incrementAt_creates_new_row_when_none_exists(): void
    {
        $this->service->incrementAt($this->product->id, $this->location->id, 5);

        $stock = ProductStock::where('product_id', $this->product->id)
            ->where('location_id', $this->location->id)
            ->first();

        $this->assertNotNull($stock);
        $this->assertEquals(5, $stock->quantity);
        $this->assertEquals(5, $this->product->fresh()->quantity);
    }

    public function test_incrementAt_adds_to_existing_stock(): void
    {
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 10]);

        $this->service->incrementAt($this->product->id, $this->location->id, 5);

        $stock = ProductStock::where('product_id', $this->product->id)
            ->where('location_id', $this->location->id)
            ->first();

        $this->assertEquals(15, $stock->quantity);
        $this->assertEquals(15, $this->product->fresh()->quantity);
    }

    public function test_syncProductQuantity_matches_sum_of_all_locations(): void
    {
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 10]);
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location2->id, 'quantity' => 7]);

        $this->service->syncProductQuantity($this->product->id);

        $this->assertEquals(17, $this->product->fresh()->quantity);
    }

    public function test_totalStock_returns_sum_across_locations(): void
    {
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 10]);
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $this->location2->id, 'quantity' => 5]);

        $total = $this->service->totalStock($this->product->id);

        $this->assertEquals(15, $total);
    }
}
