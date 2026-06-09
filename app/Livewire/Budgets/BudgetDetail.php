<?php

namespace App\Livewire\Budgets;

use Livewire\Component;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Services\Accounting\BudgetProjectionService;

class BudgetDetail extends Component
{
    public int $budgetId;

    public function mount(int $budget): void
    {
        $this->budgetId = $budget;
    }

    public function updateLine(int $lineId, string $field, $value): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        // Acota la línea a ESTE presupuesto (evita editar líneas de otro por id).
        $line = BudgetLine::where('budget_id', $this->budgetId)->findOrFail($lineId);

        if ($field === 'growth_pct') {
            $line->growth_pct = ($value === '' || $value === null) ? null : (float) $value;
        } elseif ($field === 'base_amount') {
            $line->base_amount = (int) round(((float) $value) * 100);
        }

        $line->save();
    }

    public function render()
    {
        $b   = Budget::with('lines')->findOrFail($this->budgetId);
        $svc = app(BudgetProjectionService::class);

        return view('livewire.budgets.budget-detail', [
            'budget'     => $b,
            'project'    => $svc->project($b),
            'indicators' => $svc->indicators($b),
            'vsActual'   => $svc->budgetVsActual($b, 1),
        ]);
    }
}
