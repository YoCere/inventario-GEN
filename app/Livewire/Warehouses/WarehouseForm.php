<?php

namespace App\Livewire\Warehouses;

use App\Models\Warehouse;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

class WarehouseForm extends Component
{
    public bool $isEditing = false;
    public ?Warehouse $warehouse = null;

    public string $name = '';
    public ?string $address = null;
    public bool $is_active = true;
    public bool $is_default = false;

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('warehouses', 'name')->ignore($this->warehouse?->id),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
        ];
    }

    public function render()
    {
        return view('livewire.warehouses.warehouse-form');
    }

    #[On('create-warehouse')]
    public function create(): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $this->reset(['name', 'address', 'warehouse', 'isEditing', 'is_default']);
        $this->is_active = true;
        $this->dispatch('open-modal', name: 'warehouse-form-modal');
    }

    #[On('edit-warehouse')]
    public function edit(Warehouse $warehouse): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $this->warehouse = $warehouse;
        $this->name = $warehouse->name;
        $this->address = $warehouse->address;
        $this->is_active = $warehouse->is_active;
        $this->is_default = $warehouse->is_default;
        $this->isEditing = true;
        $this->dispatch('open-modal', name: 'warehouse-form-modal');
    }

    public function save(): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $validated = $this->validate();

        try {
            \DB::transaction(function () use ($validated) {
                // If setting as default, unset other defaults first
                if ($validated['is_default']) {
                    Warehouse::where('is_default', true)
                        ->when($this->warehouse, fn ($q) => $q->where('id', '!=', $this->warehouse->id))
                        ->update(['is_default' => false]);
                }

                if ($this->isEditing && $this->warehouse) {
                    $this->warehouse->update($validated);
                    $msg = 'Almacén actualizado correctamente.';
                } else {
                    Warehouse::create($validated);
                    $msg = 'Almacén creado correctamente.';
                }

                $this->dispatch('close-modal', name: 'warehouse-form-modal');
                $this->dispatch('pg:eventRefresh-warehouse-table');
                $this->dispatch('toast', message: $msg, type: 'success');
            });
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Error: ' . $e->getMessage(), type: 'error');
        }
    }
}
