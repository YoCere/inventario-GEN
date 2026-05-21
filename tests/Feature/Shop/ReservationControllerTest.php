<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Shop\Services\ShopFeatureFlag;
use Database\Seeders\CategorySeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationControllerTest extends TestCase
{
    use RefreshDatabase, EnablesShop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableShop();

        $this->seed([CategorySeeder::class, UnitSeeder::class]);

        // Admin user — ReservationService::createReservation usa
        // User::where('role','admin')->first()?->id como created_by de la venta.
        \App\Models\User::factory()->create(['role' => 'admin']);

        // Set up default warehouse + location for stock movements.
        $warehouse = Warehouse::firstOrCreate(['name' => 'Almacén Test'], ['is_default' => true, 'is_active' => true]);
        Location::firstOrCreate(
            ['warehouse_id' => $warehouse->id, 'name' => 'Default'],
            ['is_default' => true, 'is_active' => true]
        );
    }

    public function test_reservation_creates_pending_web_sale_and_decrements_stock(): void
    {
        $product = $this->publicProductWithStock(10);

        $response = $this->postJson('/tienda/reservar', [
            'buyer_name' => 'Juan Pérez',
            'buyer_phone' => '70012345',
            'items' => [
                ['product_id' => $product->id, 'qty' => 2],
            ],
        ]);

        $response->assertOk()->assertJsonStructure([
            'ok', 'sale_id', 'invoice_number', 'whatsapp_url',
        ]);
        $this->assertTrue($response->json('ok'));

        $sale = Sale::findOrFail($response->json('sale_id'));
        $this->assertSame('web', $sale->source);
        $this->assertSame('Juan Pérez', $sale->buyer_name);
        $this->assertSame('70012345', $sale->buyer_phone);
        $this->assertSame(\App\Enums\SaleStatus::PENDING, $sale->status);

        $this->assertSame(8, $product->fresh()->quantity, 'Stock debe decrementarse en 2');
    }

    public function test_reservation_rejects_when_stock_insufficient(): void
    {
        $product = $this->publicProductWithStock(1);

        $response = $this->postJson('/tienda/reservar', [
            'buyer_name' => 'Ana',
            'buyer_phone' => '70011111',
            'items' => [
                ['product_id' => $product->id, 'qty' => 5],
            ],
        ]);

        $response->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertSame(1, $product->fresh()->quantity, 'Stock no debe cambiar al rechazar');
    }

    public function test_reservation_rejects_empty_cart(): void
    {
        $this->postJson('/tienda/reservar', [
            'buyer_name' => 'Ana',
            'buyer_phone' => '70011111',
            'items' => [],
        ])->assertStatus(422);
    }

    public function test_reservation_rejects_non_public_product(): void
    {
        $product = Product::factory()->create([
            'is_public' => false,
            'quantity' => 10,
        ]);
        $this->ensureStockRow($product, 10);

        $this->postJson('/tienda/reservar', [
            'buyer_name' => 'Ana',
            'buyer_phone' => '70011111',
            'items' => [['product_id' => $product->id, 'qty' => 1]],
        ])->assertStatus(422);
    }

    public function test_reservation_validates_buyer_name_and_phone(): void
    {
        $product = $this->publicProductWithStock(5);

        $this->postJson('/tienda/reservar', [
            'buyer_name' => 'A', // too short
            'buyer_phone' => '12', // too short
            'items' => [['product_id' => $product->id, 'qty' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors(['buyer_name', 'buyer_phone']);
    }

    private function publicProductWithStock(int $qty): Product
    {
        $product = Product::factory()->public($qty)->create();
        $this->ensureStockRow($product, $qty);
        return $product->fresh();
    }

    private function ensureStockRow(Product $product, int $qty): void
    {
        $location = Location::query()->where('is_default', true)->firstOrFail();
        ProductStock::firstOrCreate(
            ['product_id' => $product->id, 'location_id' => $location->id],
            ['quantity' => $qty]
        );
    }
}
