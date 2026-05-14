<?php

namespace App\Livewire\Locations;

use App\Models\Location;
use App\Models\Warehouse;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

class LocationForm extends Component
{
    public bool $isEditing = false;
    public ?Location $location = null;

    public ?int $warehouse_id = null;
    public ?int $parent_location_id = null;
    public string $name = '';
    public string $type = 'section';
    public bool $is_active = true;
    public bool $is_default = false;

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'parent_location_id' => ['nullable', 'exists:locations,id'],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', 'in:section,position,bin'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
        ];
    }

    public function render()
    {
        return view('livewire.locations.location-form', [
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(),
            'parentOptions' => $this->warehouse_id
                ? Location::where('warehouse_id', $this->warehouse_id)
                    ->when($this->location, fn ($q) => $q->where('id', '!=', $this->location->id))
                    ->orderBy('name')
                    ->get()
                : collect(),
        ]);
    }

    #[On('create-location')]
    public function create(): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $this->reset(['name', 'parent_location_id', 'location', 'isEditing', 'is_default']);
        $this->is_active = true;
        $this->type = 'section';
        $this->warehouse_id = Warehouse::where('is_default', true)->value('id');
        $this->dispatch('open-modal', name: 'location-form-modal');
    }

    #[On('edit-location')]
    public function edit(Location $location): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $this->location = $location;
        $this->warehouse_id = $location->warehouse_id;
        $this->parent_location_id = $location->parent_location_id;
        $this->name = $location->name;
        $this->type = $location->type;
        $this->is_active = $location->is_active;
        $this->is_default = $location->is_default;
        $this->isEditing = true;
        $this->dispatch('open-modal', name: 'location-form-modal');
    }

    public function save(): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $validated = $this->validate();

        // Prevent setting self as parent
        if (
            $this->isEditing
            && $this->location
            && $validated['parent_location_id'] === $this->location->id
        ) {
            $this->dispatch('toast', message: 'Una ubicación no puede ser su propio padre.', type: 'error');
            return;
        }

        try {
            \DB::transaction(function () use ($validated) {
                if ($validated['is_default']) {
                    // Only one default per warehouse
                    Location::where('warehouse_id', $validated['warehouse_id'])
                        ->where('is_default', true)
                        ->when($this->location, fn ($q) => $q->where('id', '!=', $this->location->id))
                        ->update(['is_default' => false]);
                }

                if ($this->isEditing && $this->location) {
                    $this->location->update($validated);
                    $msg = 'Ubicación actualizada correctamente.';
                } else {
                    Location::create($validated);
                    $msg = 'Ubicación creada correctamente.';
                }

                $this->dispatch('close-modal', name: 'location-form-modal');
                $this->dispatch('pg:eventRefresh-location-table');
                $this->dispatch('toast', message: $msg, type: 'success');
            });
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Error: ' . $e->getMessage(), type: 'error');
        }
    }
}
