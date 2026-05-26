<?php

namespace App\Livewire\AccountingPeriods;

use App\Enums\AccountingPeriodStatus;
use App\Models\AccountingPeriod;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

class AccountingPeriodForm extends Component
{
    public string $name       = '';
    public string $start_date = '';
    public string $end_date   = '';

    public function rules(): array
    {
        return [
            'name'       => ['required', 'string', 'max:50', Rule::unique('accounting_periods', 'name')],
            'start_date' => ['required', 'date'],
            'end_date'   => ['required', 'date', 'after:start_date'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique'         => 'Ya existe un periodo con ese nombre.',
            'end_date.after'      => 'La fecha de fin debe ser posterior a la de inicio.',
            'start_date.required' => 'La fecha de inicio es obligatoria.',
            'end_date.required'   => 'La fecha de fin es obligatoria.',
        ];
    }

    public function render()
    {
        return view('livewire.accounting-periods.accounting-period-form');
    }

    #[On('create-accounting-period')]
    public function create(): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $this->reset(['name', 'start_date', 'end_date']);

        // Sugerir el siguiente año como nombre y fechas por defecto
        $lastPeriod = AccountingPeriod::orderByDesc('end_date')->first();
        if ($lastPeriod) {
            $nextYear = $lastPeriod->end_date->addDay();
            $this->name       = (string) $nextYear->year;
            $this->start_date = $nextYear->startOfYear()->format('Y-m-d');
            $this->end_date   = $nextYear->endOfYear()->format('Y-m-d');
        } else {
            $this->name       = (string) now()->year;
            $this->start_date = now()->startOfYear()->format('Y-m-d');
            $this->end_date   = now()->endOfYear()->format('Y-m-d');
        }

        $this->dispatch('open-modal', name: 'accounting-period-form-modal');
    }

    public function save(): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $this->validate();

        // Check no date overlap with existing periods
        $overlap = AccountingPeriod::query()
            ->where(function ($q) {
                $q->whereBetween('start_date', [$this->start_date, $this->end_date])
                  ->orWhereBetween('end_date', [$this->start_date, $this->end_date])
                  ->orWhere(function ($q2) {
                      $q2->where('start_date', '<=', $this->start_date)
                         ->where('end_date', '>=', $this->end_date);
                  });
            })
            ->exists();

        if ($overlap) {
            $this->addError('start_date', 'Las fechas se superponen con un periodo existente.');
            return;
        }

        AccountingPeriod::create([
            'name'       => $this->name,
            'start_date' => $this->start_date,
            'end_date'   => $this->end_date,
            'status'     => AccountingPeriodStatus::Open->value,
        ]);

        $this->dispatch('close-modal', name: 'accounting-period-form-modal');
        $this->dispatch('pg:eventRefresh-accounting-period-table');
        $this->dispatch('toast', message: "Periodo '{$this->name}' creado correctamente.", type: 'success');
    }
}
