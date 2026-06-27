<?php

namespace App\Livewire\Products;

use App\DTOs\ProductData;
use App\Models\Category;
use App\Models\Unit;
use App\Services\ProductService;
use App\Services\Receipt\ProductMatcher;
use App\Services\Receipt\ReceiptParseException;
use App\Services\Receipt\ReceiptParser;
use Illuminate\Support\Facades\Log;
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

    /** @var array<int,array{name:string,purchase_price:float,quantity:int,category_id:?int,unit_id:?int,include:bool,exists:bool}> */
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
        $this->validate([
            'receipt' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:15360'],
        ]);

        $this->analyzing = true;
        try {
            $data  = $parser->parse($this->receipt);
            $match = $matcher->match($data);

            // Nombres (lowercase) que casaron contra el catálogo → ya existen.
            $existingNames = collect($match['matched'])
                ->pluck('raw_name')
                ->map(fn ($n) => mb_strtolower($n))
                ->all();

            $this->rows = collect($data->lines)->map(function ($line) use ($existingNames) {
                $exists = in_array(mb_strtolower($line->rawName), $existingNames, true);

                return [
                    'name'           => $line->rawName,
                    'purchase_price' => round($line->unitPrice / 100, 2), // céntimos → decimal para el input
                    'quantity'       => $line->quantity,
                    'category_id'    => $this->defaultCategoryId,
                    'unit_id'        => $this->defaultUnitId,
                    'include'        => ! $exists,
                    'exists'         => $exists,
                ];
            })->all();
        } catch (ReceiptParseException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
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
