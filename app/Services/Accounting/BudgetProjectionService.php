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
     * Returns key investment indicators (VAN/NPV, TIR/IRR, payback, benefit-cost ratio)
     * computed over the multi-year projected net flows.
     *
     * @return array{investment_base:int,discount_rate_pct:float,van:int|null,tir_annual_pct:float|null,payback_years:float|null,benefit_cost_ratio:float|null}
     */
    public function indicators(Budget $budget): array
    {
        $proj         = $this->project($budget);
        $netFlows     = array_map(fn ($y) => $y['net_flow'], $proj['years']);
        $investmentBase = $this->investmentBase();
        $discount     = (float) $budget->discount_rate_pct / 100;

        $van = null; $tirAnnual = null; $payback = null;
        if ($investmentBase > 0) {
            $series   = array_merge([-1 * $investmentBase], $netFlows);
            $van      = (int) round($this->metrics->npv($series, $discount));
            $irr      = $this->metrics->irr($series);
            $tirAnnual = $irr !== null ? round($irr * 100, 2) : null;
            $payback  = $this->metrics->payback($investmentBase, $netFlows);
        }

        $incomeFlows = array_map(fn ($y) => $y['income'], $proj['years']);
        $costFlows   = array_map(fn ($y) => $y['cost'] + $y['expense'], $proj['years']);
        $vanIncome   = $this->metrics->npv(array_merge([0], $incomeFlows), $discount);
        $vanCost     = $this->metrics->npv(array_merge([0], $costFlows), $discount);
        $bc          = $vanCost > 0 ? round($vanIncome / $vanCost, 2) : null;

        return [
            'investment_base'   => $investmentBase,
            'discount_rate_pct' => (float) $budget->discount_rate_pct,
            'van'               => $van,
            'tir_annual_pct'    => $tirAnnual,
            'payback_years'     => $payback,
            'benefit_cost_ratio' => $bc,
        ];
    }

    /**
     * Determines the investment base from active fixed assets.
     * Falls back to the `opening_balance_amount` setting when no assets exist.
     */
    private function investmentBase(): int
    {
        $assets = (int) \App\Models\FixedAsset::query()
            ->where('status', '!=', \App\Enums\FixedAssetStatus::Disposed->value)
            ->sum('acquisition_cost');
        if ($assets > 0) {
            return $assets;
        }
        return (int) round((float) \App\Models\Setting::get('opening_balance_amount', '0'));
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
