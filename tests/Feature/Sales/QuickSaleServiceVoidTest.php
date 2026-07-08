<?php

namespace Tests\Feature\Sales;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\QuickSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickSaleServiceVoidTest extends TestCase
{
    use RefreshDatabase;

    private QuickSaleService $service;
    private Product $product;
    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        // Seeders contables (createSale/cancelSale los requieren). Copiar nombres exactos
        // desde tests/Feature/Sales/SaleServiceTest.php si difieren.
        $this->seed([
            \Database\Seeders\AccountingPeriodSeeder::class,
            \Database\Seeders\ChartOfAccountSeeder::class,
            \Database\Seeders\SettingSeeder::class,
        ]);

        $this->service = app(QuickSaleService::class);
        $this->seller = User::factory()->staff()->create();

        $warehouse = Warehouse::create(['name' => 'Almacén', 'code' => 'ALM']);
        $location = Location::create(['name' => 'Estante', 'code' => 'EST', 'warehouse_id' => $warehouse->id]);
        $this->product = Product::factory()->create(['selling_price' => 2000, 'purchase_price' => 1800, 'quantity' => 0]);
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $location->id, 'quantity' => 10]);
        $this->product->update(['quantity' => 10]);
    }

    private function sell(?int $actorId = null): Sale
    {
        return $this->service->sell($this->product, 2, null, PaymentMethod::CASH, 0, $actorId ?? $this->seller->id)['sale'];
    }

    public function test_void_own_recent_sale_restores_stock(): void
    {
        $sale = $this->sell();
        $this->assertSame(8, $this->product->fresh()->quantity);

        $this->service->void($sale, $this->seller);

        $this->assertSame(SaleStatus::CANCELLED, $sale->fresh()->status);
        $this->assertSame(10, $this->product->fresh()->quantity);
    }

    public function test_non_admin_cannot_void_another_sellers_sale(): void
    {
        $other = User::factory()->staff()->create();
        $sale = $this->sell($other->id);

        $this->expectException(\RuntimeException::class);
        try {
            $this->service->void($sale, $this->seller);
        } finally {
            $this->assertSame(SaleStatus::COMPLETED, $sale->fresh()->status);
        }
    }

    public function test_non_admin_cannot_void_outside_window(): void
    {
        $sale = $this->sell();
        // created_at no está en $fillable de Sale; update() lo ignoraría silenciosamente.
        $sale->forceFill(['created_at' => now()->subMinutes(QuickSaleService::UNDO_WINDOW_MINUTES + 1)])->save();

        $this->expectException(\RuntimeException::class);
        $this->service->void($sale->fresh(), $this->seller);
    }

    public function test_admin_can_void_any_sale_anytime(): void
    {
        $admin = User::factory()->admin()->create();
        $sale = $this->sell();
        $sale->forceFill(['created_at' => now()->subHours(5)])->save();

        $this->service->void($sale->fresh(), $admin);

        $this->assertSame(SaleStatus::CANCELLED, $sale->fresh()->status);
    }

    public function test_double_void_fails_second_and_does_not_double_restore_stock(): void
    {
        $sale = $this->sell(); // stock 10 → 8

        $this->service->void($sale, $this->seller); // 8 → 10
        $this->assertSame(10, $this->product->fresh()->quantity);

        // Segundo intento (doble-tap): debe fallar y NO volver a sumar stock.
        try {
            $this->service->void($sale->fresh(), $this->seller);
            $this->fail('Se esperaba RuntimeException en la segunda anulación.');
        } catch (\RuntimeException $e) {
            // esperado
        }

        $this->assertSame(10, $this->product->fresh()->quantity); // no 12
    }

    public function test_void_last_cancels_the_most_recent_sale(): void
    {
        $first = $this->sell();
        $second = $this->sell();

        $cancelled = $this->service->voidLast($this->seller);

        $this->assertSame($second->id, $cancelled->id);
        $this->assertSame(SaleStatus::COMPLETED, $first->fresh()->status);
    }
}
