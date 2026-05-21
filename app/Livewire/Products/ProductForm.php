<?php

namespace App\Livewire\Products;

use App\DTOs\ProductData;
use App\Exceptions\ProductException;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\ProductService;
use App\Shop\Models\ProductImage;
use App\Shop\Services\ImageProcessor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

class ProductForm extends Component
{
    use WithFileUploads;

    public bool $isEditing = false;
    public ?Product $product = null;

    // Form Fields
    public ?string $sku = null;
    public string $name = '';
    public ?int $category_id = null;
    public ?int $unit_id = null;
    public int $purchase_price = 0;
    public int $selling_price = 0;
    public int $quantity = 0;
    public int $min_stock = 0;
    public bool $is_active = true;
    public bool $is_public = false;
    public bool $featured = false;
    public string $description = '';
    public string $notes = '';
    public ?int $location_id = null;
    public bool $hasMultiLocationStock = false;

    // Legacy single photo (backward compat) — UI nueva usa $gallery.
    public $photo = null;

    /**
     * Uploads múltiples nuevos. Cada item es un TemporaryUploadedFile.
     * @var array
     */
    public array $gallery = [];

    /**
     * IDs de ProductImage existentes a eliminar al guardar.
     * @var int[]
     */
    public array $imagesToDelete = [];

    /**
     * ID de ProductImage que el usuario quiere marcar como primary.
     * Null = sin cambio (mantiene el primary actual).
     */
    public ?int $primaryImageId = null;

    // Select Options (Removed for AJAX)
    public ?string $categoryName = null;
    public ?string $unitName = null;

    public function mount()
    {
        // No options to load
    }

    #[On('create-product')]
    public function create(): void
    {
        abort_if(! auth()->user()->isAdmin(), 403);
        $this->reset([
            'sku', 'name', 'category_id', 'unit_id', 'purchase_price', 'selling_price',
            'quantity', 'min_stock', 'description', 'notes', 'product', 'isEditing',
            'categoryName', 'unitName', 'photo', 'location_id', 'hasMultiLocationStock',
            'is_public', 'featured', 'gallery', 'imagesToDelete', 'primaryImageId',
        ]);
        $this->is_active = true;
        $this->is_public = false;
        $this->featured = false;
        $this->location_id = Location::default()?->id;

        $this->dispatch('open-modal', name: 'product-form-modal');
    }

    #[On('edit-product')]
    public function edit(Product $product): void
    {
        abort_if(! auth()->user()->isAdmin(), 403);
        $this->product = $product->load('images');
        $this->sku = $product->sku;
        $this->name = $product->name;
        $this->category_id = $product->category_id;
        $this->unit_id = $product->unit_id;
        $this->purchase_price = $product->purchase_price;
        $this->selling_price = $product->selling_price;
        $this->quantity = $product->quantity;
        $this->min_stock = $product->min_stock;
        $this->is_active = $product->is_active;
        $this->is_public = (bool) $product->is_public;
        $this->featured = (bool) $product->featured;
        $this->description = $product->description ?? '';
        $this->notes = $product->notes ?? '';
        $this->photo = null;
        $this->gallery = [];
        $this->imagesToDelete = [];
        $this->primaryImageId = $product->images->firstWhere('is_primary', true)?->id;

        // Load product stock(s) info
        $stocks = $product->stocks()->get();
        $this->hasMultiLocationStock = $stocks->count() > 1;
        $this->location_id = $stocks->first()?->location_id ?? Location::default()?->id;

        // Set initial labels for TomSelect
        $this->categoryName = $product->category ? $product->category->name : null;
        $this->unitName = $product->unit ? "{$product->unit->name} ({$product->unit->symbol})" : null;

        $this->isEditing = true;

        $this->dispatch('open-modal', name: 'product-form-modal');
    }

    public function rules()
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'sku' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('products', 'sku')->ignore($this->product?->id),
            ],
            'category_id' => ['required', 'exists:categories,id'],
            'unit_id' => ['required', 'exists:units,id'],
            'purchase_price' => ['required', 'integer', 'min:0'],
            'selling_price' => ['required', 'integer', 'min:0'],
            'quantity' => ['required', 'integer', 'min:0'],
            'min_stock' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
            'featured' => ['boolean'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'location_id' => ['nullable', 'exists:locations,id'],
            // Galería: array de uploads. Cada uno máximo 4MB (más que single porque ahora
            // genera 3 variantes WebP optimizadas — input original puede ser pesado).
            'gallery' => ['nullable', 'array', 'max:10'],
            'gallery.*' => ['image', 'max:4096', 'mimes:jpg,jpeg,png,webp'],
        ];
    }

    /**
     * Marca una imagen existente para eliminación al guardar.
     */
    public function toggleDeleteImage(int $imageId): void
    {
        if (in_array($imageId, $this->imagesToDelete, true)) {
            $this->imagesToDelete = array_values(array_diff($this->imagesToDelete, [$imageId]));
        } else {
            $this->imagesToDelete[] = $imageId;
        }
    }

    /**
     * Cambia la imagen primary (solo entre las existentes, no entre uploads nuevos —
     * los nuevos heredan primary del primero en su orden).
     */
    public function markPrimary(int $imageId): void
    {
        if (in_array($imageId, $this->imagesToDelete, true)) {
            return; // No marcar primary una imagen que se va a borrar.
        }
        $this->primaryImageId = $imageId;
    }

    /**
     * Quita un upload de la galería antes de guardar.
     */
    public function removeNewUpload(int $index): void
    {
        unset($this->gallery[$index]);
        $this->gallery = array_values($this->gallery);
    }

    public function render()
    {
        return view('livewire.products.product-form', [
            'locations' => Location::with('warehouse')
                ->where('is_active', true)
                ->orderBy('warehouse_id')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function save(ProductService $service, ImageProcessor $imageProcessor): void
    {
        abort_if(! auth()->user()->isAdmin(), 403);
        $validated = $this->validate();

        // Imagen legacy single (campo $photo) — solo si se subió y NO hay galería múltiple.
        // Mantiene backward-compat para sistemas que aún usen el campo viejo.
        $legacyImagePath = null;
        if ($this->photo && empty($this->gallery)) {
            try {
                $legacyImagePath = $this->photo->store('products', 'public');
            } catch (\Throwable $e) {
                \Log::error('Legacy product photo store failed', ['error' => $e->getMessage()]);
                $this->dispatch('toast', message: 'Error al guardar imagen: ' . $e->getMessage(), type: 'error');
                return;
            }
        } elseif ($this->isEditing && $this->product) {
            $legacyImagePath = $this->product->image_path;
        }

        $validated['image_path'] = $legacyImagePath;
        $data = ProductData::fromArray($validated);

        try {
            if ($this->isEditing && $this->product) {
                $service->updateProduct($this->product, $data);
                $product = $this->product->refresh();
                $message = 'Producto actualizado correctamente.';
            } else {
                $product = $service->createProduct($data);
                $message = 'Producto creado correctamente.';
            }

            // === Gallery management ===

            // 1. Borrar imágenes marcadas para eliminación.
            if (! empty($this->imagesToDelete)) {
                $toDelete = ProductImage::whereIn('id', $this->imagesToDelete)
                    ->where('product_id', $product->id)
                    ->get();
                foreach ($toDelete as $img) {
                    $imageProcessor->deleteVariants($img->only(['path', 'path_thumb', 'path_card', 'path_full']));
                    $img->delete();
                }
            }

            // 2. Procesar uploads nuevos → generar variantes WebP → crear ProductImage rows.
            $existingCount = $product->images()->count();
            foreach ($this->gallery as $idx => $upload) {
                $paths = $imageProcessor->processForProduct($upload, $product->id);
                ProductImage::create([
                    'product_id' => $product->id,
                    'path' => $paths['path'],
                    'path_thumb' => $paths['path_thumb'],
                    'path_card' => $paths['path_card'],
                    'path_full' => $paths['path_full'],
                    'sort_order' => $existingCount + $idx,
                    'is_primary' => false, // primary se asigna abajo
                ]);
            }

            // 3. Asegurar que exactamente UNA imagen sea primary.
            //    Reglas:
            //    - Si el usuario eligió primaryImageId explícito y aún existe → usarlo.
            //    - Si no hay primary asignado pero hay imágenes → primera por sort_order.
            $this->normalizePrimary($product);

            $this->dispatch('close-modal', name: 'product-form-modal');
            $this->dispatch('pg:eventRefresh-product-table');
            $this->dispatch('toast', message: $message, type: 'success');
        } catch (ProductException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        } catch (\Throwable $e) {
            \Log::error('Product save failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->dispatch('toast', message: 'Ocurrió un error inesperado: ' . $e->getMessage(), type: 'error');
        }
    }

    private function normalizePrimary(Product $product): void
    {
        $images = $product->images()->orderBy('sort_order')->get();
        if ($images->isEmpty()) {
            return;
        }

        // Si el usuario eligió uno y todavía existe → ese.
        $desiredId = $this->primaryImageId && $images->contains('id', $this->primaryImageId)
            ? $this->primaryImageId
            : $images->first()->id;

        // Reset todos a false, marcar solo el elegido.
        ProductImage::where('product_id', $product->id)
            ->where('id', '!=', $desiredId)
            ->update(['is_primary' => false]);
        ProductImage::where('id', $desiredId)->update(['is_primary' => true]);
    }
}
