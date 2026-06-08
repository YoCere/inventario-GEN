<?php

namespace Tests\Feature\Accounting;

use App\Models\BillOfMaterial;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductionConsumption;
use App\Models\ProductionOrder;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\ProductionService;
use App\Services\StockService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionServiceTest extends TestCase
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

    private static int $invoiceSeq = 0;

    private function buyStock(int $productId, int $qty, int $unitPriceCents): void
    {
        $purchase = Purchase::create([
            'invoice_number' => 'FAC-TEST-' . $productId . '-' . (++self::$invoiceSeq),
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

    private function makeBom(): array
    {
        $pt = Product::factory()->create();
        $tela = Product::factory()->create();
        $esponja = Product::factory()->create();
        $this->buyStock($tela->id, 100, 20);
        $this->buyStock($esponja->id, 100, 10);
        $bom = BillOfMaterial::create(['product_id' => $pt->id, 'mod_rate' => 5, 'moi_rate' => 2, 'cif_rate' => 3]);
        $bom->components()->create(['component_product_id' => $tela->id, 'quantity_per_unit' => 2]);
        $bom->components()->create(['component_product_id' => $esponja->id, 'quantity_per_unit' => 1]);
        return [$bom->fresh('components'), $pt, $tela, $esponja];
    }

    public function test_produce_posts_entry_and_moves_stock(): void
    {
        [$bom, $pt, $tela, $esponja] = $this->makeBom();
        $user = User::factory()->admin()->create();

        $order = app(ProductionService::class)->produce($bom, 10, '2026-01-05', $this->locationId, $user->id);

        $this->assertEquals(500, $order->material_cost);
        $this->assertEquals(50, $order->mod_cost);
        $this->assertEquals(20, $order->moi_cost);
        $this->assertEquals(30, $order->cif_cost);
        $this->assertEquals(600, $order->total_cost);

        $this->assertEquals(80, app(StockService::class)->totalStock($tela->id));
        $this->assertEquals(90, app(StockService::class)->totalStock($esponja->id));
        $this->assertEquals(10, app(StockService::class)->totalStock($pt->id));

        $this->assertEquals(2, ProductionConsumption::where('production_order_id', $order->id)->count());

        $entry = JournalEntry::with('lines')->find($order->journal_entry_id);
        $this->assertEquals($entry->lines->sum('debit_amount'), $entry->lines->sum('credit_amount'));
        $pt106 = ChartOfAccount::where('code', '1.1.06')->first();
        $mp104 = ChartOfAccount::where('code', '1.1.04')->first();
        $cif54 = ChartOfAccount::where('code', '5.4')->first();
        $this->assertEquals(600, $entry->lines->where('chart_of_account_id', $pt106->id)->sum('debit_amount'));
        $this->assertEquals(500, $entry->lines->where('chart_of_account_id', $mp104->id)->sum('credit_amount'));
        $this->assertEquals(30, $entry->lines->where('chart_of_account_id', $cif54->id)->sum('credit_amount'));

        $this->assertEquals(60, $pt->fresh()->purchase_price);
    }

    public function test_produce_with_blended_average_cost_balances(): void
    {
        $pt = Product::factory()->create();
        $mp = Product::factory()->create();
        // Compra 1: 100 @ 20 ; Compra 2: 100 @ 21 → promedio 20.5 → round() = 21
        $this->buyStock($mp->id, 100, 20);
        $this->buyStock($mp->id, 100, 21);
        $bom = BillOfMaterial::create(['product_id' => $pt->id, 'mod_rate' => 5, 'moi_rate' => 2, 'cif_rate' => 3]);
        $bom->components()->create(['component_product_id' => $mp->id, 'quantity_per_unit' => 2]);
        $user = User::factory()->admin()->create();

        $order = app(ProductionService::class)->produce($bom->fresh('components'), 10, '2026-01-10', $this->locationId, $user->id);

        // material = 20 units * round(avg 20.5)=21 = 420; the entry ALWAYS balances
        $entry = JournalEntry::with('lines')->find($order->journal_entry_id);
        $this->assertEquals($entry->lines->sum('debit_amount'), $entry->lines->sum('credit_amount'));
        $this->assertEquals(420, $order->material_cost); // 20 * 21
        $this->assertEquals($order->material_cost + $order->mod_cost + $order->moi_cost + $order->cif_cost, $order->total_cost);
    }

    public function test_insufficient_stock_throws_and_rolls_back(): void
    {
        [$bom, $pt, $tela] = $this->makeBom();
        $user = User::factory()->admin()->create();

        $this->expectException(\RuntimeException::class);
        try {
            app(ProductionService::class)->produce($bom, 1000, '2026-01-05', $this->locationId, $user->id);
        } finally {
            $this->assertEquals(0, ProductionOrder::count());
            $this->assertEquals(100, app(StockService::class)->totalStock($tela->id));
        }
    }
}
