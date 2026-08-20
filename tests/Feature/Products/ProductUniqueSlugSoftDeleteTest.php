<?php

namespace Tests\Feature\Products;

use App\DTOs\ProductData;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductUniqueSlugSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function makeData(string $name): ProductData
    {
        return ProductData::fromArray([
            'category_id'    => Category::factory()->create()->id,
            'unit_id'        => Unit::factory()->create()->id,
            'name'           => $name,
            'purchase_price' => 3000,
            'selling_price'  => 7000,
            'quantity'       => 5,
            'min_stock'      => 1,
            'is_active'      => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $warehouse = Warehouse::create(['name' => 'Almacén Principal', 'is_default' => true, 'is_active' => true]);
        Location::create(['warehouse_id' => $warehouse->id, 'name' => 'Estante Principal', 'is_default' => true]);
    }

    public function test_recreating_product_with_same_name_as_soft_deleted_gets_unique_slug(): void
    {
        $service = app(ProductService::class);

        $first = $service->createProduct($this->makeData('Mouse M90'));
        $this->assertSame('mouse-m90', $first->slug);

        // Borrado lógico: la fila queda con deleted_at pero ocupa el slug en el índice único.
        $first->delete();
        $this->assertSoftDeleted('products', ['id' => $first->id]);

        // Recrear con el mismo nombre NO debe chocar con 1062: debe generar slug único.
        $second = $service->createProduct($this->makeData('Mouse M90'));

        $this->assertNotSame($first->slug, $second->slug);
        $this->assertSame('mouse-m90-2', $second->slug);
        $this->assertNotSame($first->sku, $second->sku);
    }
}
