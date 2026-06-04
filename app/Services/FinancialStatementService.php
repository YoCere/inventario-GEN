<?php

namespace App\Services;

use App\Models\Setting;
use App\Services\Accounting\LedgerBalanceService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FinancialStatementService
{
    public function __construct(private readonly LedgerBalanceService $ledger)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $dateFrom, string $dateTo, bool $withTaxes = false): array
    {
        $from = Carbon::parse($dateFrom)->toDateString();
        $to = Carbon::parse($dateTo)->toDateString();
        $beforeFrom = Carbon::parse($from)->subDay()->toDateString();

        $periodBalances = $this->calculateAccountBalances($from, $to);
        $cumulativeBalances = $this->calculateAccountBalances(null, $to);
        $openingBalances = $this->calculateAccountBalances(null, $beforeFrom);

        $balanceGeneral = $this->buildBalanceGeneral($cumulativeBalances);
        $estadoResultados = $this->buildIncomeStatement($periodBalances, $withTaxes);
        $netResultForEquity = $withTaxes
            ? $estadoResultados['net_result_after_tax']
            : $estadoResultados['net_result'];
        $estadoPatrimonio = $this->buildEquityStatement($openingBalances, $cumulativeBalances, $netResultForEquity);
        $flujoEfectivo = $this->buildCashFlowStatement($from, $to);
        $netResultForIndicators = $withTaxes
            ? $estadoResultados['net_result_after_tax']
            : $estadoResultados['net_result'];
        $indicadoresInversion = $this->buildInvestmentIndicators($from, $to, $netResultForIndicators);
        $notas = $this->buildNotes($from, $to, $balanceGeneral, $estadoResultados, $flujoEfectivo, $indicadoresInversion);

        return [
            'from' => $from,
            'to' => $to,
            'balance_general' => $balanceGeneral,
            'estado_resultados' => $estadoResultados,
            'estado_patrimonio' => $estadoPatrimonio,
            'flujo_efectivo' => $flujoEfectivo,
            'indicadores_inversion' => $indicadoresInversion,
            'with_taxes' => $withTaxes,
            'notas' => $notas,
        ];
    }

    /**
     * @return Collection<int, object>
     */
    protected function calculateAccountBalances(?string $from, string $to): Collection
    {
        $rows = $from === null
            ? $this->ledger->balancesAt($to, true)
            : $this->ledger->movementsBetween($from, $to, false);

        return $rows->map(function ($r) {
            return (object) [
                'id'             => (int) $r->chart_of_account_id,
                'code'           => $r->code,
                'name'           => $r->name,
                'account_type'   => $r->account_type,
                'normal_balance' => $r->normal_balance,
                'debit_total'    => (int) $r->debit,
                'credit_total'   => (int) $r->credit,
                'balance'        => (int) $r->balance,
            ];
        });
    }

    /**
     * @param Collection<int, object> $balances
     * @return array<string, mixed>
     */
    protected function buildBalanceGeneral(Collection $balances): array
    {
        $assets = $balances->where('account_type', 'asset')->values();
        $liabilities = $balances->where('account_type', 'liability')->values();
        $equity = $balances->where('account_type', 'equity')->values();

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'assets_total' => (int) $assets->sum('balance'),
            'liabilities_total' => (int) $liabilities->sum('balance'),
            'equity_total' => (int) $equity->sum('balance'),
        ];
    }

    /**
     * @param Collection<int, object> $periodBalances
     * @return array<string, mixed>
     */
    protected function buildIncomeStatement(Collection $periodBalances, bool $withTaxes): array
    {
        $income = $periodBalances->where('account_type', 'income')->values();
        $costs = $periodBalances->where('account_type', 'cost')->values();
        $expenses = $periodBalances->where('account_type', 'expense')->values();

        $incomeTotal = (int) $income->sum('balance');
        $costTotal = (int) $costs->sum('balance');
        $expenseTotal = (int) $expenses->sum('balance');
        $netResult = $incomeTotal - $costTotal - $expenseTotal;
        $taxes = $this->buildTaxBreakdown($incomeTotal, $costTotal, $expenseTotal, $withTaxes);

        return [
            'income_accounts' => $income,
            'cost_accounts' => $costs,
            'expense_accounts' => $expenses,
            'income_total' => $incomeTotal,
            'cost_total' => $costTotal,
            'expense_total' => $expenseTotal,
            'net_result' => $netResult,
            'with_taxes' => $withTaxes,
            'taxes' => $taxes,
            'net_result_after_tax' => $netResult - $taxes['total_tax'],
        ];
    }

    /**
     * @return array<string, int|float|bool>
     */
    protected function buildTaxBreakdown(int $incomeTotal, int $costTotal, int $expenseTotal, bool $withTaxes): array
    {
        $ivaRate = (float) Setting::get('tax_iva_rate', '13');
        $itRate = (float) Setting::get('tax_it_rate', '3');
        $includeIva = Setting::get('tax_include_iva', '1') === '1';
        $includeIt = Setting::get('tax_include_it', '1') === '1';

        if (! $withTaxes) {
            return [
                'include_iva' => $includeIva,
                'include_it' => $includeIt,
                'iva_rate' => $ivaRate,
                'it_rate' => $itRate,
                'taxable_sales_base' => 0,
                'taxable_purchases_base' => 0,
                'iva_debito' => 0,
                'iva_credito' => 0,
                'iva_determinado' => 0,
                'it_base' => 0,
                'it_amount' => 0,
                'total_tax' => 0,
            ];
        }

        $taxableSalesBase = max($incomeTotal, 0);
        $taxablePurchasesBase = max($costTotal + $expenseTotal, 0);

        $ivaDebito = $includeIva
            ? (int) round($taxableSalesBase * ($ivaRate / 100))
            : 0;
        $ivaCredito = $includeIva
            ? (int) round($taxablePurchasesBase * ($ivaRate / 100))
            : 0;
        $ivaDeterminado = max($ivaDebito - $ivaCredito, 0);

        $itBase = max($incomeTotal, 0);
        $itAmount = $includeIt
            ? (int) round($itBase * ($itRate / 100))
            : 0;

        return [
            'include_iva' => $includeIva,
            'include_it' => $includeIt,
            'iva_rate' => $ivaRate,
            'it_rate' => $itRate,
            'taxable_sales_base' => $taxableSalesBase,
            'taxable_purchases_base' => $taxablePurchasesBase,
            'iva_debito' => $ivaDebito,
            'iva_credito' => $ivaCredito,
            'iva_determinado' => $ivaDeterminado,
            'it_base' => $itBase,
            'it_amount' => $itAmount,
            'total_tax' => $ivaDeterminado + $itAmount,
        ];
    }

    /**
     * @param Collection<int, object> $openingBalances
     * @param Collection<int, object> $closingBalances
     * @return array<string, mixed>
     */
    protected function buildEquityStatement(Collection $openingBalances, Collection $closingBalances, int $netResult): array
    {
        $openingEquity = (int) $openingBalances->where('account_type', 'equity')->sum('balance');
        $closingEquity = (int) $closingBalances->where('account_type', 'equity')->sum('balance');

        return [
            'opening_equity' => $openingEquity,
            'period_result' => $netResult,
            'closing_equity' => $closingEquity,
            'changes' => $closingEquity - $openingEquity,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildCashFlowStatement(string $from, string $to): array
    {
        $cashCodes = ['1.1.01', '1.1.02'];

        $rows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.chart_of_account_id')
            ->select(
                'coa.code',
                'coa.name',
                DB::raw('SUM(jel.debit_amount) as inflow'),
                DB::raw('SUM(jel.credit_amount) as outflow')
            )
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '>=', $from)
            ->whereDate('je.entry_date', '<=', $to)
            ->whereIn('coa.code', $cashCodes)
            ->groupBy('coa.code', 'coa.name')
            ->orderBy('coa.code')
            ->get()
            ->map(function ($row) {
                $row->inflow = (int) $row->inflow;
                $row->outflow = (int) $row->outflow;
                $row->net = (int) $row->inflow - (int) $row->outflow;

                return $row;
            });

        return [
            'cash_accounts' => $rows,
            'total_inflow' => (int) $rows->sum('inflow'),
            'total_outflow' => (int) $rows->sum('outflow'),
            'net_change' => (int) $rows->sum('net'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildInvestmentIndicators(string $from, string $to, int $netResult): array
    {
        $investmentBase = (int) round((float) Setting::get('opening_balance_amount', '0'));
        $openingBalanceDate = Setting::get('opening_balance_date');
        $discountRateAnnual = (float) Setting::get('discount_rate_annual', '12');
        $discountRateMonthly = (($discountRateAnnual / 100) > -1)
            ? (((1 + ($discountRateAnnual / 100)) ** (1 / 12)) - 1)
            : null;

        $roiPercent = null;
        if ($investmentBase > 0) {
            $roiPercent = round(($netResult / $investmentBase) * 100, 2);
        }

        $monthlyCashFlows = $this->buildMonthlyCashFlows($from, $to);
        $tirMonthlyPercent = null;
        $tirAnnualPercent = null;
        $vanAmount = null;
        $paybackMonths = null;
        $paybackLabel = null;

        if ($investmentBase > 0) {
            $series = array_merge([-1 * $investmentBase], $monthlyCashFlows['values']);
            $irr = $this->estimateIrr($series);
            $paybackMonths = $this->estimatePaybackMonths($investmentBase, $monthlyCashFlows['values']);
            $paybackLabel = $this->formatPayback($paybackMonths);

            if ($irr !== null) {
                $tirMonthlyPercent = round($irr * 100, 2);
                $tirAnnualPercent = round((((1 + $irr) ** 12) - 1) * 100, 2);
            }

            if ($discountRateMonthly !== null) {
                $vanAmount = $this->calculateNpv($series, $discountRateMonthly);
            }
        }

        return [
            'investment_base' => $investmentBase,
            'opening_balance_date' => $openingBalanceDate,
            'discount_rate_annual' => round($discountRateAnnual, 2),
            'roi_percent' => $roiPercent,
            'tir_monthly_percent' => $tirMonthlyPercent,
            'tir_annual_percent' => $tirAnnualPercent,
            'van_amount' => $vanAmount !== null ? (int) round($vanAmount) : null,
            'payback_months' => $paybackMonths,
            'payback_label' => $paybackLabel,
            'cashflow_months' => $monthlyCashFlows['months'],
            'cashflow_values' => $monthlyCashFlows['values'],
            'analysis' => $this->buildInvestmentAnalysis($roiPercent, $tirAnnualPercent, $vanAmount, $investmentBase),
        ];
    }

    /**
     * @return array{months: array<int, string>, values: array<int, int>}
     */
    protected function buildMonthlyCashFlows(string $from, string $to): array
    {
        $cashCodes = ['1.1.01', '1.1.02'];

        $dailyRows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.chart_of_account_id')
            ->select('je.entry_date')
            ->selectRaw('SUM(jel.debit_amount) as day_debit')
            ->selectRaw('SUM(jel.credit_amount) as day_credit')
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '>=', $from)
            ->whereDate('je.entry_date', '<=', $to)
            ->whereIn('coa.code', $cashCodes)
            ->groupBy('je.entry_date')
            ->orderBy('je.entry_date')
            ->get();

        $raw = [];
        foreach ($dailyRows as $row) {
            $monthKey = Carbon::parse($row->entry_date)->format('Y-m');
            $dayNet = ((float) $row->day_debit) - ((float) $row->day_credit);
            $raw[$monthKey] = ($raw[$monthKey] ?? 0) + $dayNet;
        }

        $months = [];
        $values = [];

        $period = CarbonPeriod::create(
            Carbon::parse($from)->startOfMonth(),
            '1 month',
            Carbon::parse($to)->startOfMonth()
        );

        foreach ($period as $monthDate) {
            $monthKey = $monthDate->format('Y-m');
            $months[] = $monthDate->format('m/Y');
            $values[] = (int) round((float) ($raw[$monthKey] ?? 0));
        }

        return [
            'months' => $months,
            'values' => $values,
        ];
    }

    protected function estimateIrr(array $cashFlows): ?float
    {
        if (count($cashFlows) < 2) {
            return null;
        }

        $hasPositive = false;
        $hasNegative = false;

        foreach ($cashFlows as $flow) {
            if ($flow > 0) {
                $hasPositive = true;
            }

            if ($flow < 0) {
                $hasNegative = true;
            }
        }

        if (! $hasPositive || ! $hasNegative) {
            return null;
        }

        $rate = 0.1;

        for ($i = 0; $i < 100; $i++) {
            $npv = 0.0;
            $derivative = 0.0;

            foreach ($cashFlows as $t => $flow) {
                $base = (1 + $rate) ** $t;
                $npv += $flow / $base;

                if ($t > 0) {
                    $derivative -= ($t * $flow) / ((1 + $rate) ** ($t + 1));
                }
            }

            if (abs($derivative) < 1e-10) {
                return null;
            }

            $nextRate = $rate - ($npv / $derivative);

            if ($nextRate <= -0.9999) {
                return null;
            }

            if (abs($nextRate - $rate) < 1e-7) {
                return $nextRate;
            }

            $rate = $nextRate;
        }

        return null;
    }

    protected function calculateNpv(array $cashFlows, float $monthlyDiscountRate): float
    {
        $npv = 0.0;

        foreach ($cashFlows as $t => $flow) {
            $npv += $flow / ((1 + $monthlyDiscountRate) ** $t);
        }

        return $npv;
    }

    protected function estimatePaybackMonths(int $investmentBase, array $monthlyFlows): ?float
    {
        if ($investmentBase <= 0) {
            return null;
        }

        $remaining = $investmentBase;

        foreach ($monthlyFlows as $index => $flow) {
            if ($flow <= 0) {
                continue;
            }

            if ($flow >= $remaining) {
                $fraction = $remaining / $flow;
                return round($index + $fraction, 2);
            }

            $remaining -= $flow;
        }

        return null;
    }

    protected function formatPayback(?float $months): ?string
    {
        if ($months === null) {
            return null;
        }

        $wholeMonths = (int) floor($months);
        $years = intdiv($wholeMonths, 12);
        $remainingMonths = $wholeMonths % 12;

        if ($years === 0) {
            return $wholeMonths . ' meses';
        }

        return $years . ' anios y ' . $remainingMonths . ' meses';
    }

    protected function buildInvestmentAnalysis(?float $roiPercent, ?float $tirAnnualPercent, ?float $vanAmount, int $investmentBase): string
    {
        if ($investmentBase <= 0) {
            return 'Defina un balance inicial mayor a cero en configuracion para habilitar ROI, TIR y VAN.';
        }

        if ($roiPercent === null || $tirAnnualPercent === null || $vanAmount === null) {
            return 'No hay suficiente historial de flujos mixtos para estimar ROI, TIR y VAN de forma confiable en el periodo.';
        }

        if ($roiPercent > 0 && $tirAnnualPercent > 0 && $vanAmount > 0) {
            return 'Indicadores positivos: mantener o ampliar inversion en lineas con mayor margen y recortar gastos de bajo retorno.';
        }

        if ($roiPercent < 0 && $tirAnnualPercent < 0 && $vanAmount < 0) {
            return 'Indicadores negativos: revisar costos fijos/variables y detener inversiones con flujo recurrentemente negativo.';
        }

        return 'Indicadores mixtos: priorizar control de gasto y validar cada inversion con flujo proyectado antes de ejecutarla.';
    }

    /**
     * @param array<string, mixed> $balanceGeneral
     * @param array<string, mixed> $estadoResultados
     * @param array<string, mixed> $flujoEfectivo
     * @param array<string, mixed> $indicadoresInversion
     * @return array<int, string>
     */
    protected function buildNotes(
        string $from,
        string $to,
        array $balanceGeneral,
        array $estadoResultados,
        array $flujoEfectivo,
        array $indicadoresInversion
    ): array {
        $roi = $indicadoresInversion['roi_percent'];
        $tir = $indicadoresInversion['tir_annual_percent'];
        $van = $indicadoresInversion['van_amount'];
        $payback = $indicadoresInversion['payback_label'];

        return [
            "Periodo analizado: {$from} a {$to}.",
            'Base de elaboracion: asientos contables contabilizados (estado = posted).',
            'Balance General: Activo total ' . format_money($balanceGeneral['assets_total']) .
                ', Pasivo total ' . format_money($balanceGeneral['liabilities_total']) .
                ', Patrimonio total ' . format_money($balanceGeneral['equity_total']) . '.',
            'Estado de Resultados: Ingresos ' . format_money($estadoResultados['income_total']) .
                ', Costos ' . format_money($estadoResultados['cost_total']) .
                ', Gastos ' . format_money($estadoResultados['expense_total']) .
                ', Resultado neto ' . format_money($estadoResultados['net_result']) .
                ($estadoResultados['with_taxes']
                    ? ', Impuestos estimados ' . format_money($estadoResultados['taxes']['total_tax']) .
                        ', Resultado neto con impuestos ' . format_money($estadoResultados['net_result_after_tax'])
                    : '') .
                '.',
            'Flujo de efectivo: Ingresos de caja/banco ' . format_money($flujoEfectivo['total_inflow']) .
                ', Egresos de caja/banco ' . format_money($flujoEfectivo['total_outflow']) .
                ', Variacion neta ' . format_money($flujoEfectivo['net_change']) . '.',
            'Indicadores de inversion: ROI ' . ($roi !== null ? ($roi . '%') : 'no disponible') .
                ', TIR anual ' . ($tir !== null ? ($tir . '%') : 'no disponible') .
                ', VAN ' . ($van !== null ? format_money($van) : 'no disponible') .
                ', Recuperacion ' . ($payback ?? 'no disponible') . '.',
        ];
    }
}
