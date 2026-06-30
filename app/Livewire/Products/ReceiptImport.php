<?php

namespace App\Livewire\Products;

use App\DTOs\ProductData;
use App\Models\Category;
use App\Models\Unit;
use App\Services\ProductService;
use App\Services\Receipt\ProductMatcher;
use App\Services\Receipt\ReceiptData;
use App\Services\Receipt\ReceiptLine;
use App\Services\Receipt\ReceiptParseException;
use App\Services\Receipt\ReceiptParser;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

class ReceiptImport extends Component
{
    use WithFileUploads;

    /** Buffer de subida: cada selección/foto se appendea a $pages y se limpia. */
    public $newPage = null;
    /** @var array Páginas acumuladas del recibo (TemporaryUploadedFile[]). */
    public array $pages = [];

    public bool $analyzing = false;
    public ?int $defaultCategoryId = null;
    public ?int $defaultUnitId = null;

    /** @var array<int,array{name:string,purchase_price:float,quantity:int,category_id:?int,unit_id:?int,include:bool,exists:bool}> */
    public array $rows = [];

    #[On('import-receipt')]
    public function open(): void
    {
        abort_if(! auth()->user()->isAdmin(), 403);
        $this->reset(['newPage', 'pages', 'rows', 'analyzing']);
        $this->dispatch('open-modal', name: 'receipt-import-modal');
    }

    /**
     * Hook Livewire: al subir (cámara de a una o varias de galería) appendea
     * al acumulado $pages en vez de reemplazar. Mismo patrón que la galería
     * de productos (newUpload → gallery).
     */
    public function updatedNewPage(): void
    {
        $incoming = is_array($this->newPage) ? $this->newPage : [$this->newPage];
        foreach ($incoming as $file) {
            if ($file) {
                $this->pages[] = $file;
            }
        }
        // Cap defensivo: máximo 15 páginas.
        if (count($this->pages) > 15) {
            $this->pages = array_slice($this->pages, 0, 15);
        }
        $this->newPage = null;
    }

    public function removePage(int $index): void
    {
        unset($this->pages[$index]);
        $this->pages = array_values($this->pages);
    }

    public function analyze(ReceiptParser $parser, ProductMatcher $matcher): void
    {
        abort_if(! auth()->user()->isAdmin(), 403);

        if (empty($this->pages)) {
            $this->dispatch('toast', message: 'Agrega al menos una foto del recibo.', type: 'info');
            return;
        }

        $this->analyzing = true;
        try {
            // Una llamada IA por página → sin truncado y resiliente. Junta todas
            // las líneas y deduplica por nombre sumando cantidades.
            $merged = []; // lowerName => ReceiptLine
            $failedPages = 0;

            foreach ($this->pages as $page) {
                try {
                    $data = $parser->parse($page);
                } catch (ReceiptParseException $e) {
                    $failedPages++;
                    continue;
                }

                foreach ($data->lines as $line) {
                    $key = mb_strtolower(trim($line->rawName));
                    if (isset($merged[$key])) {
                        $prev = $merged[$key];
                        $merged[$key] = new ReceiptLine(
                            $prev->rawName,
                            $prev->quantity + $line->quantity,
                            $prev->unitPrice, // conserva el primer precio visto
                        );
                    } else {
                        $merged[$key] = $line;
                    }
                }
            }

            if (empty($merged)) {
                $this->dispatch('toast', message: 'No se pudieron leer productos del recibo.', type: 'error');
                return;
            }

            $mergedData = new ReceiptData(null, null, array_values($merged));
            $match = $matcher->match($mergedData);

            $existingNames = collect($match['matched'])
                ->pluck('raw_name')
                ->map(fn ($n) => mb_strtolower($n))
                ->all();

            $this->rows = collect($mergedData->lines)->map(function ($line) use ($existingNames) {
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

            if ($failedPages > 0) {
                $this->dispatch('toast', message: "{$failedPages} página(s) no se pudieron leer; el resto sí.", type: 'warning');
            }
        } catch (\Throwable $e) {
            Log::error('ReceiptImport analyze error', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
                'file'      => $e->getFile() . ':' . $e->getLine(),
            ]);
            $detail = config('app.debug') ? ' (' . $e::class . ': ' . $e->getMessage() . ')' : '';
            $this->dispatch('toast', message: 'No se pudo procesar el recibo.' . $detail, type: 'error');
        } finally {
            $this->analyzing = false;
        }
    }

    /** Re-aplica la categoría/unidad por defecto a todas las filas. */
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
                    'selling_price'  => 0, // vacío a propósito → producto incompleto, resaltado en la lista
                    'quantity'       => (int) $row['quantity'],
                    'min_stock'      => 0,
                    'is_active'      => true,
                    'description'    => null,
                    'notes'          => null,
                ]);
                $service->createProduct($data);
                $created++;
            } catch (\Throwable $e) {
                Log::error('ReceiptImport createProduct failed', ['name' => $row['name'], 'error' => $e->getMessage()]);
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

        $this->reset(['newPage', 'pages', 'rows']);
    }

    public function render()
    {
        return view('livewire.products.receipt-import', [
            'categories' => Category::orderBy('name')->get(),
            'units'      => Unit::orderBy('name')->get(),
        ]);
    }
}
