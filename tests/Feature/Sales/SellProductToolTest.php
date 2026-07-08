<?php

namespace Tests\Feature\Sales;

use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Agent\AgentContext;
use App\Services\Agent\Tools\SellProductTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellProductToolTest extends TestCase
{
    use RefreshDatabase;

    private SellProductTool $tool;
    private AgentContext $context;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            \Database\Seeders\AccountingPeriodSeeder::class,
            \Database\Seeders\ChartOfAccountSeeder::class,
            \Database\Seeders\SettingSeeder::class,
        ]);

        $this->tool = app(SellProductTool::class);
        $seller = User::factory()->staff()->create();
        $this->context = new AgentContext($seller, '555', 'telegram');

        $warehouse = Warehouse::create(['name' => 'Almacén', 'code' => 'ALM']);
        $this->location = Location::create(['name' => 'Estante', 'code' => 'EST', 'warehouse_id' => $warehouse->id]);
    }

    private function stockedProduct(string $name, int $selling, int $purchase, int $stock): Product
    {
        $p = Product::factory()->create([
            'name' => $name, 'selling_price' => $selling, 'purchase_price' => $purchase, 'quantity' => 0, 'is_active' => true,
        ]);
        ProductStock::create(['product_id' => $p->id, 'location_id' => $this->location->id, 'quantity' => $stock]);
        $p->update(['quantity' => $stock]);
        return $p;
    }

    public function test_is_not_web_exposed(): void
    {
        $this->assertFalse($this->tool->webExposed());
    }

    public function test_sells_with_unit_price_override(): void
    {
        $this->stockedProduct('Cable Huawei V8', 2000, 1800, 10);

        $result = $this->tool->execute([
            'product' => 'Cable Huawei V8', 'quantity' => 3, 'unit_price' => 15,
        ], $this->context);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['below_cost']);
        $sale = Sale::find($result['sale_id']);
        $this->assertSame(4500, $sale->total);
    }

    public function test_total_price_is_divided_per_unit(): void
    {
        $this->stockedProduct('Funda A01', 3000, 1000, 10);

        $result = $this->tool->execute([
            'product' => 'Funda A01', 'quantity' => 2, 'total_price' => 50,
        ], $this->context);

        $this->assertTrue($result['ok']);
        $this->assertSame('50.00', $result['total_bs']);
    }

    public function test_returns_needs_selection_when_ambiguous(): void
    {
        $this->stockedProduct('Cargador Samsung 25w', 5000, 3000, 5);
        $this->stockedProduct('Cargador Samsung 45w', 8000, 5000, 5);

        $result = $this->tool->execute(['product' => 'Cargador Samsung'], $this->context);

        $this->assertTrue($result['needs_selection'] ?? false);
        $this->assertGreaterThan(1, count($result['options']));
    }

    public function test_returns_error_when_not_found(): void
    {
        $result = $this->tool->execute(['product' => 'ProductoInexistenteXYZ'], $this->context);

        $this->assertArrayHasKey('error', $result);
    }
}
