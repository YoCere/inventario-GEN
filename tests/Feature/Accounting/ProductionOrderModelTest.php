<?php

namespace Tests\Feature\Accounting;

use App\Models\BillOfMaterial;
use App\Models\ProductionOrder;
use App\Models\Product;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductionOrderModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_and_order_create(): void
    {
        $this->assertTrue(Schema::hasTable('production_orders'));
        $this->assertTrue(Schema::hasTable('production_consumptions'));

        $pt = Product::factory()->create();
        $bom = BillOfMaterial::create(['product_id' => $pt->id]);
        $locationId = app(StockService::class)->defaultLocationId();

        $order = ProductionOrder::create([
            'code' => 'PRD-001', 'product_id' => $pt->id, 'bom_id' => $bom->id, 'quantity' => 10,
            'production_date' => '2026-01-01', 'location_id' => $locationId, 'total_cost' => 100000, 'unit_cost' => 10000,
        ]);
        $this->assertEquals('completed', $order->status);
        $this->assertEquals(10, $order->quantity);
    }
}
