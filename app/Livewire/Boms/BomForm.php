<?php

namespace App\Livewire\Boms;

use Livewire\Component;
use Livewire\Attributes\On;
use App\Models\BillOfMaterial;
use App\Models\Product;

class BomForm extends Component
{
    public ?int $bomId = null;
    public ?int $productId = null;
    public float $mod_rate = 0;
    public float $moi_rate = 0;
    public float $cif_rate = 0;
    public array $components = [
        ['component_product_id' => null, 'quantity_per_unit' => null],
    ];

    public function getProductOptionsProperty(): array
    {
        return Product::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => ['value' => $p->id, 'label' => $p->name])
            ->toArray();
    }

    public function addComponent(): void
    {
        $this->components[] = ['component_product_id' => null, 'quantity_per_unit' => null];
    }

    public function removeComponent(int $index): void
    {
        if (count($this->components) > 1) {
            array_splice($this->components, $index, 1);
            $this->components = array_values($this->components);
        }
    }

    public function rules(): array
    {
        return [
            'productId'                              => ['required', 'exists:products,id'],
            'mod_rate'                               => ['numeric', 'min:0'],
            'moi_rate'                               => ['numeric', 'min:0'],
            'cif_rate'                               => ['numeric', 'min:0'],
            'components'                             => ['array', 'min:1'],
            'components.*.component_product_id'      => ['required', 'exists:products,id'],
            'components.*.quantity_per_unit'         => ['required', 'numeric', 'min:0.0001'],
        ];
    }

    public function render()
    {
        return view('livewire.boms.bom-form', [
            'productOptions' => $this->productOptions,
        ]);
    }

    #[On('create-bom')]
    public function create(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $this->bomId = null;
        $this->productId = null;
        $this->mod_rate = 0;
        $this->moi_rate = 0;
        $this->cif_rate = 0;
        $this->components = [
            ['component_product_id' => null, 'quantity_per_unit' => null],
        ];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'bom-form-modal');
    }

    #[On('edit-bom')]
    public function edit(int $bomId): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $bom = BillOfMaterial::with('components')->findOrFail($bomId);
        $this->bomId = $bom->id;
        $this->productId = $bom->product_id;
        $this->mod_rate = round($bom->mod_rate / 100, 2);
        $this->moi_rate = round($bom->moi_rate / 100, 2);
        $this->cif_rate = round($bom->cif_rate / 100, 2);
        $this->components = $bom->components->map(fn ($c) => [
            'component_product_id' => $c->component_product_id,
            'quantity_per_unit'    => $c->quantity_per_unit,
        ])->toArray();
        if (empty($this->components)) {
            $this->components = [['component_product_id' => null, 'quantity_per_unit' => null]];
        }
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'bom-form-modal');
    }

    public function save(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $this->validate();

        $bom = BillOfMaterial::updateOrCreate(
            ['product_id' => $this->productId],
            [
                'mod_rate'  => (int) round($this->mod_rate * 100),
                'moi_rate'  => (int) round($this->moi_rate * 100),
                'cif_rate'  => (int) round($this->cif_rate * 100),
                'is_active' => true,
            ]
        );

        $bom->components()->delete();
        foreach ($this->components as $component) {
            $bom->components()->create([
                'component_product_id' => $component['component_product_id'],
                'quantity_per_unit'    => $component['quantity_per_unit'],
            ]);
        }

        $this->dispatch('close-modal', name: 'bom-form-modal');
        $this->dispatch('pg:eventRefresh-bom-table');
        $this->dispatch('toast', message: 'Receta (BOM) guardada correctamente.', type: 'success');
    }
}
