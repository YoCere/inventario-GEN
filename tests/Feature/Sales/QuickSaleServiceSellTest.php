<?php

namespace Tests\Feature\Sales;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\QuickSaleService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickSaleServiceSellTest extends TestCase
{
    use RefreshDatabase;

    private QuickSaleService $service;
    private Product $product;
    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            AccountingPeriodSeeder::class,
            ChartOfAccountSeeder::class,
            SettingSeeder::class,
        ]);

        $this->service = app(QuickSaleService::class);
        $this->seller = User::factory()->staff()->create();

        $warehouse = Warehouse::create(['name' => 'Almacén', 'code' => 'ALM']);
        $location = Location::create(['name' => 'Estante', 'code' => 'EST', 'warehouse_id' => $warehouse->id]);

        $this->product = Product::factory()->create([
            'selling_price'  => 2000,
            'purchase_price' => 1800,
            'quantity'       => 0,
        ]);
        ProductStock::create([
            'product_id'  => $this->product->id,
            'location_id' => $location->id,
            'quantity'    => 10,
        ]);
        $this->product->update(['quantity' => 10]);
    }

    public function test_sell_creates_completed_sale_and_decrements_stock(): void
    {
        $result = $this->service->sell($this->product, 3, null, PaymentMethod::CASH, 0, $this->seller->id);

        $sale = $result['sale'];
        $this->assertSame(SaleStatus::COMPLETED, $sale->status);
        $this->assertSame(6000, $sale->total);
        $this->assertSame(7, $this->product->fresh()->quantity);
        $this->assertFalse($result['below_cost']);
    }

    public function test_sell_records_price_override_and_flags_below_cost(): void
    {
        // Vender a Bs 15 (1500) c/u cuando lista=2000 y costo=1800 → bajo costo.
        // SaleService no honra items[].unit_price; el override se traduce a descuento por unidad.
        $result = $this->service->sell($this->product, 3, 1500, PaymentMethod::CASH, 0, $this->seller->id);

        $sale = $result['sale'];
        $this->assertSame(4500, $sale->total);    // 3 × 1500 efectivo
        $this->assertTrue($result['below_cost']); // 1500 < 1800
    }

    public function test_sell_applies_discount(): void
    {
        $result = $this->service->sell($this->product, 2, null, PaymentMethod::CASH, 500, $this->seller->id);

        $this->assertSame(3500, $result['sale']->total);
    }

    public function test_sell_caps_price_at_list_and_flags_when_above(): void
    {
        // Piden 2500/u pero la lista es 2000: se cobra a lista y se marca price_capped.
        $result = $this->service->sell($this->product, 2, 2500, PaymentMethod::CASH, 0, $this->seller->id);

        $this->assertTrue($result['price_capped']);
        $this->assertSame(4000, $result['sale']->total); // 2 × 2000 (lista), no 2 × 2500
    }

    public function test_sell_does_not_flag_capped_at_or_below_list(): void
    {
        $result = $this->service->sell($this->product, 1, 1500, PaymentMethod::CASH, 0, $this->seller->id);

        $this->assertFalse($result['price_capped']);
    }

    public function test_sell_throws_on_insufficient_stock(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->sell($this->product, 99, null, PaymentMethod::CASH, 0, $this->seller->id);
    }

    public function test_sell_normalizes_saleservice_exception_to_runtime(): void
    {
        // La ubicación real solo tiene 5, pero product->quantity (agregado) sigue en 10,
        // así el pre-check pasa para qty 7 y createSale (por ubicación) lanza SaleException.
        ProductStock::where('product_id', $this->product->id)->update(['quantity' => 5]);
        $this->product->update(['quantity' => 10]);

        $this->assertSame(10, $this->product->fresh()->quantity);

        $this->expectException(\RuntimeException::class);
        $this->service->sell($this->product->fresh(), 7, null, PaymentMethod::CASH, 0, $this->seller->id);
    }
}
