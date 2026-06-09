<?php

namespace App\Livewire\Budgets;

use Livewire\Component;
use Livewire\Attributes\On;
use App\Models\Budget;
use App\Services\Accounting\BudgetProjectionService;

class BudgetForm extends Component
{
    public string $name = '';
    public string $base_from = '';
    public string $base_to = '';
    public int $years = 5;
    public float $growth_pct = 3;
    public float $discount_rate_pct = 12;
    public float $iue_rate_pct = 25;

    public bool $open = false;

    public function rules(): array
    {
        return [
            'name'              => ['required', 'string', 'max:255'],
            'base_from'         => ['required', 'date'],
            'base_to'           => ['required', 'date', 'after_or_equal:base_from'],
            'years'             => ['required', 'integer', 'min:1', 'max:20'],
            'growth_pct'        => ['required', 'numeric', 'min:0'],
            'discount_rate_pct' => ['required', 'numeric', 'min:0'],
            'iue_rate_pct'      => ['required', 'numeric', 'min:0'],
        ];
    }

    #[On('create-budget')]
    public function openModal(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $this->reset(['name', 'base_from', 'base_to']);
        $this->years = 5;
        $this->growth_pct = 3;
        $this->discount_rate_pct = 12;
        $this->iue_rate_pct = 25;
        $this->open = true;
        $this->dispatch('open-modal', name: 'budget-form-modal');
    }

    public function save(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $this->validate();

        $budget = Budget::create([
            'name'              => $this->name,
            'base_from'         => $this->base_from,
            'base_to'           => $this->base_to,
            'years'             => $this->years,
            'growth_pct'        => $this->growth_pct,
            'discount_rate_pct' => $this->discount_rate_pct,
            'iue_rate_pct'      => $this->iue_rate_pct,
            'created_by'        => auth()->id(),
        ]);

        app(BudgetProjectionService::class)->seedFromActuals($budget);

        session()->flash('success', 'Presupuesto creado y siembra completada.');

        $this->redirect(route('finance.budgets.show', $budget->id), navigate: true);
    }

    public function render()
    {
        return view('livewire.budgets.budget-form');
    }
}
