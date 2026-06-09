<?php

namespace App\Services\Accounting;

use App\Models\Budget;

class BudgetProjectionService
{
    public function __construct(
        private LedgerBalanceService $ledger,
        private InvestmentMetrics $metrics,
    ) {
    }

    /**
     * Siembra budget_lines desde los movimientos reales del rango base
     * (cuentas income/cost/expense con saldo del periodo).
     */
    public function seedFromActuals(Budget $budget): void
    {
        $rows = $this->ledger->movementsBetween(
            $budget->base_from->toDateString(),
            $budget->base_to->toDateString()
        );

        foreach ($rows as $row) {
            if (! in_array($row->account_type, ['income', 'cost', 'expense'], true)) {
                continue;
            }
            $amount = (int) $row->balance;
            if ($amount === 0) {
                continue;
            }
            $budget->lines()->updateOrCreate(
                ['chart_of_account_code' => $row->code],
                ['name' => $row->name, 'line_type' => $row->account_type, 'base_amount' => $amount]
            );
        }
    }
}
