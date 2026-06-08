<?php

namespace Tests\Feature\Accounting;

use App\Models\BillOfMaterial;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductionUiTest extends TestCase
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

    public function test_tables_render(): void
    {
        $admin = \App\Models\User::factory()->admin()->create();
        Livewire::actingAs($admin)->test(\App\Livewire\Boms\BomTable::class)->assertOk();
        Livewire::actingAs($admin)->test(\App\Livewire\Production\ProductionOrderTable::class)->assertOk();
    }

    public function test_produce_via_form(): void
    {
        $admin = \App\Models\User::factory()->admin()->create();
        $pt = \App\Models\Product::factory()->create();
        $mp = \App\Models\Product::factory()->create();
        $this->buyStock($mp->id, 100, 20);
        $bom = \App\Models\BillOfMaterial::create(['product_id' => $pt->id, 'mod_rate' => 5, 'moi_rate' => 2, 'cif_rate' => 3]);
        $bom->components()->create(['component_product_id' => $mp->id, 'quantity_per_unit' => 2]);

        Livewire::actingAs($admin)->test(\App\Livewire\Production\ProduceForm::class)
            ->set('bomId', $bom->id)->set('quantity', 10)
            ->set('production_date', '2026-01-05')->set('location_id', $this->locationId)
            ->call('save')->assertHasNoErrors();

        $this->assertEquals(1, \App\Models\ProductionOrder::count());
        $this->assertEquals(10, app(\App\Services\StockService::class)->totalStock($pt->id));
    }

    public function test_non_admin_cannot_produce(): void
    {
        $user = \App\Models\User::factory()->create();
        Livewire::actingAs($user)->test(\App\Livewire\Production\ProduceForm::class)
            ->set('bomId', 1)->set('quantity', 10)->set('production_date', '2026-01-05')->set('location_id', $this->locationId)
            ->call('save')->assertStatus(403);
    }
}
