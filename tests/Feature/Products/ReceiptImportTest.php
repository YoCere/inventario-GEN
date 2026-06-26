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
            ->set('receipt', UploadedFile::fake()->image('recibo.jpg'))
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
