# Importar Productos de Recibo + Incompletos — Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans. Steps use checkbox (`- [ ]`).

**Goal:** Importar productos nuevos desde foto de recibo (lote, categoría/unidad default editable, stock inicial) y resaltar/filtrar productos incompletos (sin precio venta / sin foto) en la lista.

**Architecture:** Reusa `ReceiptParser`/`ProductMatcher`/`ProductService` existentes. Parte A = componente Livewire `ReceiptImport` (modal). Parte B = mejoras a PowerGrid `ProductTable` (row rule + toggles).

**Tech Stack:** Laravel 12, Livewire 3, PowerGrid 6.7.3, PHPUnit.

---

## Task 1 — Parte B: ProductTable resalte + toggles

**Files:**
- Modify: `app/Livewire/Products/ProductTable.php`
- Create: `resources/views/livewire/products/product-table-toggles.blade.php`
- Test: `tests/Feature/Products/ProductTableIncompleteTest.php`

- [ ] Test: con `onlyMissingPrice=true` el datasource excluye `selling_price>0`; con `onlyMissingPhoto=true` excluye los que tienen imágenes.

```php
<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Livewire\Products\ProductTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductTableIncompleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_missing_price_filters_products_with_selling_price(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $withPrice = Product::factory()->create(['selling_price' => 5000]);
        $noPrice   = Product::factory()->create(['selling_price' => 0]);

        Livewire::test(ProductTable::class)
            ->set('onlyMissingPrice', true)
            ->assertSee($noPrice->sku)
            ->assertDontSee($withPrice->sku);
    }

    public function test_only_missing_photo_filters_products_without_images(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $withPhoto = Product::factory()->create();
        ProductImage::create([
            'product_id' => $withPhoto->id, 'path' => 'p.webp',
            'path_thumb' => 't.webp', 'path_card' => 'c.webp', 'path_full' => 'f.webp',
            'sort_order' => 0, 'is_primary' => true,
        ]);
        $noPhoto = Product::factory()->create();

        Livewire::test(ProductTable::class)
            ->set('onlyMissingPhoto', true)
            ->assertSee($noPhoto->sku)
            ->assertDontSee($withPhoto->sku);
    }
}
```

- [ ] Run: `php artisan test tests/Feature/Products/ProductTableIncompleteTest.php` → FAIL (props no existen).

- [ ] Implementar en `ProductTable`:
  - Añadir import `use PowerComponents\LivewirePowerGrid\Facades\Rule;`
  - Props públicas: `public bool $onlyMissingPrice = false;` `public bool $onlyMissingPhoto = false;`
  - `datasource()`:
    ```php
    public function datasource(): Builder
    {
        $query = Product::query()->with(['category', 'unit']);
        if ($this->onlyMissingPrice) {
            $query->where('selling_price', '<=', 0);
        }
        if ($this->onlyMissingPhoto) {
            $query->whereDoesntHave('images');
        }
        return $query;
    }
    ```
  - En `setUp()`, header: `PowerGrid::header()->showSearchInput()->includeViewOnTop('livewire.products.product-table-toggles')`.
  - Añadir método:
    ```php
    public function actionRules($row): array
    {
        return [
            Rule::rows()
                ->when(fn ($product) => (int) $product->selling_price <= 0)
                ->setAttribute('class', '!bg-yellow-50'),
        ];
    }
    ```
    (Si ya existe `actionRules`, fusionar la regla.)

- [ ] Crear vista toggles `resources/views/livewire/products/product-table-toggles.blade.php`:

```blade
<div class="flex flex-wrap gap-2 mb-3">
    <button type="button" wire:click="$toggle('onlyMissingPrice')"
        @class([
            'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium border transition-colors',
            'bg-yellow-100 border-yellow-400 text-yellow-800' => $onlyMissingPrice,
            'bg-white border-gray-300 text-gray-700 hover:bg-gray-50' => ! $onlyMissingPrice,
        ])>
        <x-heroicon-o-banknotes class="w-4 h-4" />
        Sin precio de venta
    </button>
    <button type="button" wire:click="$toggle('onlyMissingPhoto')"
        @class([
            'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium border transition-colors',
            'bg-amber-100 border-amber-400 text-amber-800' => $onlyMissingPhoto,
            'bg-white border-gray-300 text-gray-700 hover:bg-gray-50' => ! $onlyMissingPhoto,
        ])>
        <x-heroicon-o-photo class="w-4 h-4" />
        Sin foto
    </button>
</div>
```

- [ ] Run test → PASS. Compilar blades. Commit.

---

## Task 2 — Parte A: componente ReceiptImport

**Files:**
- Create: `app/Livewire/Products/ReceiptImport.php`
- Create: `resources/views/livewire/products/receipt-import.blade.php`
- Test: `tests/Feature/Products/ReceiptImportTest.php`

- [ ] Test (Livewire + Http::fake del proveedor IA):

```php
<?php

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Livewire\Products\ReceiptImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ReceiptImportTest extends TestCase
{
    use RefreshDatabase;

    private function seedDefaults(): array
    {
        return [Category::factory()->create()->id, Unit::factory()->create()->id];
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

        Livewire::test(ReceiptImport::class)
            ->set('receipt', UploadedFile::fake()->image('recibo.jpg'))
            ->call('analyze')
            ->assertCount('rows', 2);
    }

    public function test_import_creates_products_with_zero_selling_price_and_stock(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$catId, $unitId] = $this->seedDefaults();

        $component = Livewire::test(ReceiptImport::class)
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
```

- [ ] Run → FAIL (clase no existe). Si `Category::factory()`/`Unit::factory()` faltan campos, ajustar el test mínimamente.

- [ ] Implementar `app/Livewire/Products/ReceiptImport.php`:

```php
<?php

namespace App\Livewire\Products;

use App\DTOs\ProductData;
use App\Models\Category;
use App\Models\Unit;
use App\Services\ProductService;
use App\Services\Receipt\ProductMatcher;
use App\Services\Receipt\ReceiptParseException;
use App\Services\Receipt\ReceiptParser;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

class ReceiptImport extends Component
{
    use WithFileUploads;

    public $receipt = null;
    public bool $analyzing = false;
    public ?int $defaultCategoryId = null;
    public ?int $defaultUnitId = null;
    /** @var array<int,array> */
    public array $rows = [];

    #[On('import-receipt')]
    public function open(): void
    {
        abort_if(! auth()->user()->isAdmin(), 403);
        $this->reset(['receipt', 'rows', 'analyzing']);
        $this->dispatch('open-modal', name: 'receipt-import-modal');
    }

    public function analyze(ReceiptParser $parser, ProductMatcher $matcher): void
    {
        abort_if(! auth()->user()->isAdmin(), 403);
        $this->validate(['receipt' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:15360']]);

        $this->analyzing = true;
        try {
            $data  = $parser->parse($this->receipt->getRealPath() ? $this->toUploadedFile() : $this->receipt);
            $match = $matcher->match($data);
            $existingNames = collect($match['matched'])->pluck('raw_name')->map(fn ($n) => mb_strtolower($n))->all();

            $this->rows = collect($data->lines)->map(function ($line) use ($existingNames) {
                $exists = in_array(mb_strtolower($line->rawName), $existingNames, true);
                return [
                    'name'           => $line->rawName,
                    'purchase_price' => round($line->unitPrice / 100, 2),
                    'quantity'       => $line->quantity,
                    'category_id'    => $this->defaultCategoryId,
                    'unit_id'        => $this->defaultUnitId,
                    'include'        => ! $exists,
                    'exists'         => $exists,
                ];
            })->all();
        } catch (ReceiptParseException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        } finally {
            $this->analyzing = false;
        }
    }

    private function toUploadedFile()
    {
        return $this->receipt; // Livewire TemporaryUploadedFile es UploadedFile-compatible
    }

    public function applyDefaultsToAll(): void
    {
        foreach ($this->rows as $i => $row) {
            $this->rows[$i]['category_id'] = $this->defaultCategoryId;
            $this->rows[$i]['unit_id'] = $this->defaultUnitId;
        }
    }

    public function import(ProductService $service): void
    {
        abort_if(! auth()->user()->isAdmin(), 403);

        $created = 0;
        $failed = [];
        foreach ($this->rows as $row) {
            if (empty($row['include'])) {
                continue;
            }
            if (empty($row['name']) || empty($row['category_id']) || empty($row['unit_id'])) {
                $failed[] = $row['name'] ?? '(sin nombre)';
                continue;
            }
            try {
                $data = ProductData::fromArray([
                    'category_id'    => (int) $row['category_id'],
                    'unit_id'        => (int) $row['unit_id'],
                    'name'           => $row['name'],
                    'purchase_price' => (int) round(((float) $row['purchase_price']) * 100),
                    'selling_price'  => 0,
                    'quantity'       => (int) $row['quantity'],
                    'min_stock'      => 0,
                    'is_active'      => true,
                    'description'    => null,
                    'notes'          => null,
                ]);
                $service->createProduct($data);
                $created++;
            } catch (\Throwable $e) {
                \Log::error('ReceiptImport createProduct failed', ['name' => $row['name'], 'error' => $e->getMessage()]);
                $failed[] = $row['name'];
            }
        }

        $this->dispatch('pg:eventRefresh-product-table');
        $this->dispatch('close-modal', name: 'receipt-import-modal');
        $msg = "{$created} producto(s) creado(s).";
        if ($failed) {
            $msg .= ' Fallaron: ' . implode(', ', $failed) . '.';
        }
        $this->dispatch('toast', message: $msg, type: $failed ? 'warning' : 'success');
        $this->reset(['receipt', 'rows']);
    }

    public function render()
    {
        return view('livewire.products.receipt-import', [
            'categories' => Category::orderBy('name')->get(),
            'units'      => Unit::orderBy('name')->get(),
        ]);
    }
}
```

Nota implementador: simplificar `analyze()` para llamar `$parser->parse($this->receipt)` directamente (TemporaryUploadedFile extiende UploadedFile). Eliminar el helper `toUploadedFile()` si `parse()` acepta el upload Livewire (verificar firma — `ReceiptParser::parse(UploadedFile)`; `TemporaryUploadedFile` ES un `UploadedFile`). Ajustar a lo que funcione en el test.

- [ ] Crear vista `resources/views/livewire/products/receipt-import.blade.php` con modal `<x-modal name="receipt-import-modal">`: input file `data-heic-aware` wire:model="receipt", botón Analizar (wire:click="analyze", spinner en `analyzing`), selects default categoría/unidad + botón "Aplicar a todas" (wire:click="applyDefaultsToAll"), tabla editable de `rows` (inputs wire:model por fila: name, purchase_price, quantity, selects category_id/unit_id, checkbox include; filas `exists` atenuadas con etiqueta "ya existe"), botón "Crear productos" (wire:click="import"). Reusar estilos de product-form.blade.

- [ ] Run test → PASS. Commit.

---

## Task 3 — Botón + montaje en index

**Files:**
- Modify: `resources/views/products/index.blade.php`

- [ ] Añadir botón en la barra de header (junto a "Crear producto"):
```blade
<x-secondary-button x-data x-on:click="$dispatch('import-receipt')">
    <x-heroicon-o-camera class="w-4 h-4 mr-2" />
    Importar de recibo
</x-secondary-button>
```
- [ ] Montar componente antes de `</x-app-layout>`:
```blade
<livewire:products.receipt-import />
```
- [ ] Verificación manual: botón abre modal → subir recibo → analizar → editar filas → crear → productos aparecen (amarillos por falta de precio venta). Compilar blades. Commit.

## Self-Review
- Cobertura spec: Parte A (T2/T3), Parte B (T1). Categoría/unidad default+override (T2). selling_price=0 (T2). Stock=quantity (T2). Resalte amarillo + toggles (T1). Reuso ReceiptParser/Matcher/ProductService. Admin-gated.
- Tipos: céntimos en createProduct (purchase_price ×100), selling_price 0. rows shape consistente entre analyze/import/vista.
