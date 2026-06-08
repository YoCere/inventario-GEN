<?php

namespace Tests\Feature\Accounting;

use App\Models\BillOfMaterial;
use App\Models\Location;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\ProductionCostCalculator;
use App\Services\StockService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionCostCalculatorTest extends TestCase
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

    public function test_estimate_costs(): void
    {
        $pt      = Product::factory()->create();
        $tela    = Product::factory()->create();
        $esponja = Product::factory()->create();

        $this->buyStock($tela->id, 100, 20);
        $this->buyStock($esponja->id, 100, 10);

        $bom = BillOfMaterial::create(['product_id' => $pt->id, 'mod_rate' => 5, 'moi_rate' => 2, 'cif_rate' => 3]);
        $bom->components()->create(['component_product_id' => $tela->id, 'quantity_per_unit' => 2]);
        $bom->components()->create(['component_product_id' => $esponja->id, 'quantity_per_unit' => 1]);

        $est = app(ProductionCostCalculator::class)->estimate($bom->fresh('components'), 10);

        $this->assertEquals(500, $est['material_cost']); // tela 20u@20=400 + esponja 10u@10=100
        $this->assertEquals(50, $est['mod_cost']);        // 10*5
        $this->assertEquals(20, $est['moi_cost']);        // 10*2
        $this->assertEquals(30, $est['cif_cost']);        // 10*3
        $this->assertEquals(600, $est['total_cost']);
        $this->assertEquals(60, $est['unit_cost']);       // 600/10
        $this->assertCount(2, $est['components']);
    }
}
