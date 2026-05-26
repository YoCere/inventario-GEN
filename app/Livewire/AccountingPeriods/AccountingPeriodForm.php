<?php

namespace App\Livewire\AccountingPeriods;

use App\Enums\AccountingPeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

class AccountingPeriodForm extends Component
{
    public string $period_type = 'monthly';
    public string $name        = '';
    public string $start_date  = '';
    public string $end_date    = '';

    public function rules(): array
    {
        return [
            'period_type' => ['required', 'in:monthly,quarterly,biannual,annual,custom'],
            'name'        => ['required', 'string', 'max:50', Rule::unique('accounting_periods', 'name')],
            'start_date'  => ['required', 'date'],
            'end_date'    => ['required', 'date', 'after:start_date'],
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

    public function updatedPeriodType(): void
    {
        $this->recalculateDates();
    }

    public function updatedStartDate(): void
    {
        $this->recalculateDates();
    }

    protected function recalculateDates(): void
    {
        if (empty($this->start_date) || $this->period_type === 'custom') {
            return;
        }

        try {
            $start = Carbon::parse($this->start_date);
        } catch (\Throwable) {
            return;
        }

        $this->end_date = match ($this->period_type) {
            'monthly'   => $start->copy()->endOfMonth()->format('Y-m-d'),
            'quarterly' => $start->copy()->addMonths(3)->subDay()->format('Y-m-d'),
            'biannual'  => $start->copy()->addMonths(6)->subDay()->format('Y-m-d'),
            'annual'    => $start->copy()->addYear()->subDay()->format('Y-m-d'),
            default     => $this->end_date,
        };

        $this->name = $this->autoName($start);
    }

    protected function autoName(Carbon $start): string
    {
        return match ($this->period_type) {
            'monthly'   => $this->spanishMonth($start->month) . ' ' . $start->year,
            'quarterly' => 'T' . (int) ceil($start->month / 3) . ' ' . $start->year,
            'biannual'  => ($start->month <= 6 ? 'S1' : 'S2') . ' ' . $start->year,
            'annual'    => (string) $start->year,
            default     => $this->name,
        };
    }

    protected function spanishMonth(int $m): string
    {
        return ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'][$m];
    }

    #[On('create-accounting-period')]
    public function create(string $suggestedType = '', string $suggestedStart = ''): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $this->resetErrorBag();

        $this->period_type = $suggestedType ?: Setting::get('default_accounting_period_type', 'monthly');

        if ($suggestedStart) {
            $this->start_date = $suggestedStart;
        } else {
            $lastPeriod = AccountingPeriod::orderByDesc('end_date')->first();
            $this->start_date = $lastPeriod
                ? $lastPeriod->end_date->addDay()->format('Y-m-d')
                : now()->startOfMonth()->format('Y-m-d');
        }

        $this->name     = '';
        $this->end_date = '';
        $this->recalculateDates();

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
