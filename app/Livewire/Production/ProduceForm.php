<?php

namespace App\Livewire\Production;

use Livewire\Component;
use App\Models\BillOfMaterial;
use App\Models\Location;
use App\Services\Accounting\ProductionCostCalculator;
use App\Services\Accounting\ProductionService;
use App\Services\StockService;

class ProduceForm extends Component
{
    public ?int $bomId = null;
    public int $quantity = 1;
    public string $production_date = '';
    public ?int $location_id = null;

    public function mount(): void
    {
        $this->production_date = now()->toDateString();
        try {
            $this->location_id = app(StockService::class)->defaultLocationId();
        } catch (\RuntimeException) {
            $this->location_id = null;
        }
    }

    public function getBomOptionsProperty(): array
    {
        return BillOfMaterial::where('is_active', true)
            ->with('product')
            ->get()
            ->map(fn ($b) => ['value' => $b->id, 'label' => $b->product?->name ?? 'BOM #' . $b->id])
            ->toArray();
    }

    public function getLocationOptionsProperty(): array
    {
        return Location::orderBy('name')
            ->get()
            ->map(fn ($l) => ['value' => $l->id, 'label' => $l->name])
            ->toArray();
    }

    public function getEstimateProperty(): ?array
    {
        if (!$this->bomId || !$this->quantity || $this->quantity < 1) {
            return null;
        }
        $bom = BillOfMaterial::with('components')->find($this->bomId);
        if (!$bom) {
            return null;
        }
        try {
            return app(ProductionCostCalculator::class)->estimate($bom, (int) $this->quantity, $this->location_id);
        } catch (\Throwable) {
            return null;
        }
    }

    public function rules(): array
    {
        return [
            'bomId'           => ['required', 'exists:bills_of_material,id'],
            'quantity'        => ['required', 'integer', 'min:1'],
            'production_date' => ['required', 'date'],
            'location_id'     => ['required', 'exists:locations,id'],
        ];
    }

    public function render()
    {
        return view('livewire.production.produce-form', [
            'bomOptions'      => $this->bomOptions,
            'locationOptions' => $this->locationOptions,
            'estimate'        => $this->estimate,
        ]);
    }

    public function save(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $this->validate();

        try {
            $bom = BillOfMaterial::with('components')->findOrFail($this->bomId);
            app(ProductionService::class)->produce(
                $bom,
                (int) $this->quantity,
                $this->production_date,
                (int) $this->location_id,
                auth()->id()
            );

            session()->flash('success', 'Orden de producción registrada correctamente.');
            $this->dispatch('pg:eventRefresh-production-order-table');
            $this->reset(['bomId', 'quantity', 'production_date']);
            $this->quantity = 1;
            $this->production_date = now()->toDateString();
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
            session()->flash('error', $e->getMessage());
        }
    }
}
