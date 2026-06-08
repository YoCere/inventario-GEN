<?php

namespace Tests\Feature\Accounting;

use App\Models\BillOfMaterial;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductionConsumption;
use App\Models\ProductionOrder;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\KardexService;
use App\Services\StockService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KardexProductionTest extends TestCase
{
    use RefreshDatabase;

    private int $locationId;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([AccountingPeriodSeeder::class, ChartOfAccountSeeder::class, SettingSeeder::class]);

        // Create default warehouse + location required by StockService::defaultLocationId()
        $warehouse = Warehouse::create(['name' => 'Almacén Principal', 'is_default' => true]);
        $location  = Location::create([
            'warehouse_id' => $warehouse->id,
            'name'         => 'Estante Principal',
            'is_default'   => true,
        ]);
        $this->locationId = $location->id;

        // Purchases require supplier_id + created_by (NOT NULL)
        $this->supplierId = Supplier::factory()->create()->id;
        $this->userId     = User::factory()->create()->id;
    }

    private function buyStock(int $productId, int $qty, int $unitPriceCents): void
    {
        $purchase = Purchase::create([
            'invoice_number' => 'FAC-TEST-' . $productId,
            'purchase_date'  => '2026-01-01',
            'status'         => 'received',
            'total'          => $qty * $unitPriceCents,
            'supplier_id'    => $this->supplierId,
            'created_by'     => $this->userId,
        ]);
        $purchase->items()->create([
            'product_id'  => $productId,
            'quantity'    => $qty,
            'unit_price'  => $unitPriceCents,
            'subtotal'    => $qty * $unitPriceCents,
            'location_id' => $this->locationId,
        ]);
        app(StockService::class)->incrementAt($productId, $this->locationId, $qty);
    }

    public function test_average_unit_cost_from_purchases(): void
    {
        $mp = Product::factory()->create();
        $this->buyStock($mp->id, 100, 50);

        $this->assertEquals(50, app(KardexService::class)->averageUnitCost($mp->id));
    }

    public function test_production_consumption_and_output_appear_in_kardex(): void
    {
        $mp = Product::factory()->create();
        $pt = Product::factory()->create();
        $this->buyStock($mp->id, 100, 50);

        $bom = BillOfMaterial::create(['product_id' => $pt->id]);
        $order = ProductionOrder::create([
            'code'            => 'PRD-1',
            'product_id'      => $pt->id,
            'bom_id'          => $bom->id,
            'quantity'        => 10,
            'production_date' => '2026-01-05',
            'location_id'     => $this->locationId,
            'material_cost'   => 2000,
            'total_cost'      => 2500,
            'unit_cost'       => 250,
        ]);
        ProductionConsumption::create([
            'production_order_id'  => $order->id,
            'component_product_id' => $mp->id,
            'quantity'             => 40,
            'unit_cost'            => 50,
            'total_cost'           => 2000,
        ]);
        app(StockService::class)->decrementAt($mp->id, $this->locationId, 40);
        app(StockService::class)->incrementAt($pt->id, $this->locationId, 10);

        $kardexMp = app(KardexService::class)->build($mp->id, '2026-01-01', '2026-01-31');
        $this->assertEquals(60, $kardexMp['totals']['closing_qty']);
        $this->assertEquals(40, $kardexMp['totals']['exit_qty']);

        $kardexPt = app(KardexService::class)->build($pt->id, '2026-01-01', '2026-01-31');
        $this->assertEquals(10, $kardexPt['totals']['closing_qty']);
        $this->assertEquals(10, $kardexPt['totals']['entry_qty']);
    }
}
