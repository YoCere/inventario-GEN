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
     * Multi-year projection with compound growth + IUE.
     *
     * Each line grows independently: uses its own growth_pct override when set,
     * otherwise the budget's global growth_pct.  Year 1 = base amounts (k=1, so
     * (1+g)^0 = 1). IUE is applied only when operating_profit > 0.
     *
     * @return array{years: list<array{year:int,income:int,cost:int,expense:int,operating_profit:int,iue:int,net_flow:int}>}
     */
    public function project(Budget $budget): array
    {
        $years    = max(1, (int) $budget->years);
        $globalG  = (float) $budget->growth_pct / 100;
        $iueRate  = (float) $budget->iue_rate_pct / 100;

        $byYear = [];
        for ($k = 1; $k <= $years; $k++) {
            $income = 0; $cost = 0; $expense = 0;
            foreach ($budget->lines as $line) {
                $g      = $line->growth_pct !== null ? ((float) $line->growth_pct / 100) : $globalG;
                $amount = (int) round((int) $line->base_amount * ((1 + $g) ** ($k - 1)));
                if ($line->line_type === 'income') {
                    $income += $amount;
                } elseif ($line->line_type === 'cost') {
                    $cost += $amount;
                } else {
                    $expense += $amount;
                }
            }
            $operating = $income - $cost - $expense;
            $iue       = $operating > 0 ? (int) round($operating * $iueRate) : 0;
            $net       = $operating - $iue;
            $byYear[]  = [
                'year'             => $k,
                'income'           => $income,
                'cost'             => $cost,
                'expense'          => $expense,
                'operating_profit' => $operating,
                'iue'              => $iue,
                'net_flow'         => $net,
            ];
        }

        return ['years' => $byYear];
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
