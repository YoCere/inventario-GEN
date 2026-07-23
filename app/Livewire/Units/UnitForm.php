<?php

namespace App\Livewire\Units;

use Livewire\Component;
use App\Models\Unit;
use App\DTOs\UnitData;
use Livewire\Attributes\On;
use Illuminate\Validation\Rule;
use App\Services\UnitService;
use App\Exceptions\UnitException;

class UnitForm extends Component
{
    public bool $isEditing = false;
    public ?Unit $unit = null;

    public string $name = '';
    public string $symbol = '';
    public string $sin_code = '';

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('units', 'name')->ignore($this->unit?->id),
            ],
            'symbol' => ['required', 'string', 'max:10'],
            'sin_code' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function render()
    {
        return view('livewire.units.unit-form');
    }

    #[On('create-unit')]
    public function create(): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $this->reset(['name', 'symbol', 'sin_code', 'unit', 'isEditing']);
        $this->dispatch('open-modal', name: 'unit-form-modal');
    }

    #[On('edit-unit')]
    public function edit(Unit $unit): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $this->unit = $unit;
        $this->name = $unit->name;
        $this->symbol = $unit->symbol;
        $this->sin_code = $unit->sin_code ?? '';
        $this->isEditing = true;
        $this->dispatch('open-modal', name: 'unit-form-modal');
    }

    public function save(UnitService $service): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $validated = $this->validate();

        $data = UnitData::fromArray($validated);

        try {
            if ($this->isEditing && $this->unit) {
                $service->updateUnit($this->unit, $data);
                $message = 'Unidad actualizada correctamente.';
            } else {
                $service->createUnit($data);
                $message = 'Unidad creada correctamente.';
            }

            $this->dispatch('close-modal', name: 'unit-form-modal');
            $this->dispatch('pg:eventRefresh-unit-table');
            $this->dispatch('toast', message: $message, type: 'success');
        } catch (UnitException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Ocurrió un error inesperado.', type: 'error');
        }
    }
}
