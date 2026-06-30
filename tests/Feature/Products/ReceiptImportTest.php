<?php

namespace Tests\Feature\Products;

use App\Livewire\Products\ReceiptImport;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ReceiptImportTest extends TestCase
{
    use RefreshDatabase;

    private function defaultLocation(): void
    {
        $warehouse = Warehouse::create(['name' => 'Almacén Principal', 'is_default' => true]);
        Location::create(['warehouse_id' => $warehouse->id, 'name' => 'Estante Principal', 'is_default' => true]);
    }

    public function test_analyze_populates_rows_and_marks_existing(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Product::factory()->create(['name' => 'Vidrio Templado A10', 'sku' => 'VTA10']);
        Setting::set('ai_provider', 'anthropic');
        Setting::set('anthropic_api_key', 'sk-test');

        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"purchase_date":null,"supplier_name":null,"items":[{"raw_name":"Vidrio Templado A10","quantity":50,"unit_price":2.53},{"raw_name":"Mica Nueva XYZ","quantity":20,"unit_price":1.00}]}']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], 200)]);

        $component = Livewire::test(ReceiptImport::class)
            ->set('newPage', UploadedFile::fake()->image('recibo.jpg'))
            ->call('analyze')
            ->assertCount('rows', 2);

        $rows = $component->get('rows');
        // La línea que casa un producto existente queda marcada exists + excluida.
        $existing = collect($rows)->firstWhere('name', 'Vidrio Templado A10');
        $this->assertTrue($existing['exists']);
        $this->assertFalse($existing['include']);
        // La nueva queda incluida.
        $nueva = collect($rows)->firstWhere('name', 'Mica Nueva XYZ');
        $this->assertFalse($nueva['exists']);
        $this->assertTrue($nueva['include']);
    }

    public function test_analyze_merges_pages_and_sums_quantity_for_same_product(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Setting::set('ai_provider', 'anthropic');
        Setting::set('anthropic_api_key', 'sk-test');

        // Página 1: Mica Nueva XYZ x20. Página 2: Mica Nueva XYZ x5 + Otro x3.
        Http::fakeSequence()
            ->push(['content' => [['type' => 'text', 'text' => '{"items":[{"raw_name":"Mica Nueva XYZ","quantity":20,"unit_price":1.00}]}']], 'usage' => ['input_tokens' => 5, 'output_tokens' => 5]])
            ->push(['content' => [['type' => 'text', 'text' => '{"items":[{"raw_name":"Mica Nueva XYZ","quantity":5,"unit_price":1.00},{"raw_name":"Otro Producto","quantity":3,"unit_price":2.00}]}']], 'usage' => ['input_tokens' => 5, 'output_tokens' => 5]]);

        $component = Livewire::test(ReceiptImport::class)
            ->set('newPage', UploadedFile::fake()->image('p1.jpg'))
            ->set('newPage', UploadedFile::fake()->image('p2.jpg'))
            ->call('analyze');

        $rows = $component->get('rows');
        $this->assertCount(2, $rows); // dedup: Mica + Otro
        $mica = collect($rows)->firstWhere('name', 'Mica Nueva XYZ');
        $this->assertSame(25, $mica['quantity']); // 20 + 5
    }

    public function test_bulk_price_applies_to_selected_rows_and_imports(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->defaultLocation();
        $catId  = Category::factory()->create()->id;
        $unitId = Unit::factory()->create()->id;

        Livewire::test(ReceiptImport::class)
            ->set('rows', [
                ['name' => 'Vidrio A', 'purchase_price' => 2.53, 'selling_price' => 0, 'quantity' => 50, 'category_id' => $catId, 'unit_id' => $unitId, 'include' => true, 'exists' => false, 'selected' => true],
                ['name' => 'Vidrio B', 'purchase_price' => 2.53, 'selling_price' => 0, 'quantity' => 20, 'category_id' => $catId, 'unit_id' => $unitId, 'include' => true, 'exists' => false, 'selected' => true],
                ['name' => 'Vidrio C', 'purchase_price' => 2.53, 'selling_price' => 0, 'quantity' => 10, 'category_id' => $catId, 'unit_id' => $unitId, 'include' => true, 'exists' => false, 'selected' => false],
            ])
            ->set('bulkPrice', 10)
            ->set('bulkTarget', 'selling')
            ->call('applyBulkPrice')
            ->assertSet('rows.0.selling_price', 10.0)
            ->assertSet('rows.1.selling_price', 10.0)
            ->assertSet('rows.2.selling_price', 0) // no seleccionada → intacta
            ->call('import');

        // A y B con precio venta 1000 céntimos; C queda en 0.
        $this->assertDatabaseHas('products', ['name' => 'Vidrio A', 'selling_price' => 1000]);
        $this->assertDatabaseHas('products', ['name' => 'Vidrio B', 'selling_price' => 1000]);
        $this->assertDatabaseHas('products', ['name' => 'Vidrio C', 'selling_price' => 0]);
    }

    public function test_import_creates_products_with_zero_selling_price_and_stock(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->defaultLocation();
        $catId  = Category::factory()->create()->id;
        $unitId = Unit::factory()->create()->id;

        Livewire::test(ReceiptImport::class)
            ->set('defaultCategoryId', $catId)
            ->set('defaultUnitId', $unitId)
            ->set('rows', [
                ['name' => 'Mica Nueva XYZ', 'purchase_price' => 1.00, 'quantity' => 20, 'category_id' => $catId, 'unit_id' => $unitId, 'include' => true, 'exists' => false],
                ['name' => 'Excluido', 'purchase_price' => 5.00, 'quantity' => 3, 'category_id' => $catId, 'unit_id' => $unitId, 'include' => false, 'exists' => false],
            ])
            ->call('import');

        $this->assertDatabaseHas('products', ['name' => 'Mica Nueva XYZ', 'selling_price' => 0, 'quantity' => 20]);
        $this->assertDatabaseMissing('products', ['name' => 'Excluido']);
    }
}
