<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FinancialStatementService
{
    /**
     * @return array<string, mixed>
     */
    public function build(string $dateFrom, string $dateTo): array
    {
        $from = Carbon::parse($dateFrom)->toDateString();
        $to = Carbon::parse($dateTo)->toDateString();
        $beforeFrom = Carbon::parse($from)->subDay()->toDateString();

        $periodBalances = $this->calculateAccountBalances($from, $to);
        $cumulativeBalances = $this->calculateAccountBalances(null, $to);
        $openingBalances = $this->calculateAccountBalances(null, $beforeFrom);

        $balanceGeneral = $this->buildBalanceGeneral($cumulativeBalances);
        $estadoResultados = $this->buildIncomeStatement($periodBalances);
        $estadoPatrimonio = $this->buildEquityStatement($openingBalances, $cumulativeBalances, $estadoResultados['net_result']);
        $flujoEfectivo = $this->buildCashFlowStatement($from, $to);
        $notas = $this->buildNotes($from, $to, $balanceGeneral, $estadoResultados, $flujoEfectivo);

        return [
            'from' => $from,
            'to' => $to,
            'balance_general' => $balanceGeneral,
            'estado_resultados' => $estadoResultados,
            'estado_patrimonio' => $estadoPatrimonio,
            'flujo_efectivo' => $flujoEfectivo,
            'notas' => $notas,
        ];
    }

    /**
     * @return Collection<int, object>
     */
    protected function calculateAccountBalances(?string $from, string $to): Collection
    {
        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.chart_of_account_id')
            ->select(
                'coa.id',
                'coa.code',
                'coa.name',
                'coa.account_type',
                'coa.normal_balance',
                DB::raw('SUM(jel.debit_amount) as debit_total'),
                DB::raw('SUM(jel.credit_amount) as credit_total')
            )
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '<=', $to)
            ->groupBy('coa.id', 'coa.code', 'coa.name', 'coa.account_type', 'coa.normal_balance')
            ->orderBy('coa.code');

        if ($from) {
            $query->whereDate('je.entry_date', '>=', $from);
        }

        return $query->get()->map(function ($row) {
            $debit = (int) $row->debit_total;
            $credit = (int) $row->credit_total;

            $balance = $row->normal_balance === 'debit'
                ? ($debit - $credit)
                : ($credit - $debit);

            $row->debit_total = $debit;
            $row->credit_total = $credit;
            $row->balance = $balance;

            return $row;
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
    protected function buildIncomeStatement(Collection $periodBalances): array
    {
        $income = $periodBalances->where('account_type', 'income')->values();
        $costs = $periodBalances->where('account_type', 'cost')->values();
        $expenses = $periodBalances->where('account_type', 'expense')->values();

        $incomeTotal = (int) $income->sum('balance');
        $costTotal = (int) $costs->sum('balance');
        $expenseTotal = (int) $expenses->sum('balance');
        $netResult = $incomeTotal - $costTotal - $expenseTotal;

        return [
            'income_accounts' => $income,
            'cost_accounts' => $costs,
            'expense_accounts' => $expenses,
            'income_total' => $incomeTotal,
            'cost_total' => $costTotal,
            'expense_total' => $expenseTotal,
            'net_result' => $netResult,
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
     * @param array<string, mixed> $balanceGeneral
     * @param array<string, mixed> $estadoResultados
     * @param array<string, mixed> $flujoEfectivo
     * @return array<int, string>
     */
    protected function buildNotes(
        string $from,
        string $to,
        array $balanceGeneral,
        array $estadoResultados,
        array $flujoEfectivo
    ): array {
        return [
            "Periodo analizado: {$from} a {$to}.",
            'Base de elaboración: asientos contables contabilizados (estado = posted).',
            'Balance General: Activo total ' . format_money($balanceGeneral['assets_total']) .
                ', Pasivo total ' . format_money($balanceGeneral['liabilities_total']) .
                ', Patrimonio total ' . format_money($balanceGeneral['equity_total']) . '.',
            'Estado de Resultados: Ingresos ' . format_money($estadoResultados['income_total']) .
                ', Costos ' . format_money($estadoResultados['cost_total']) .
                ', Gastos ' . format_money($estadoResultados['expense_total']) .
                ', Resultado neto ' . format_money($estadoResultados['net_result']) . '.',
            'Flujo de efectivo: Ingresos de caja/banco ' . format_money($flujoEfectivo['total_inflow']) .
                ', Egresos de caja/banco ' . format_money($flujoEfectivo['total_outflow']) .
                ', Variación neta ' . format_money($flujoEfectivo['net_change']) . '.',
        ];
    }
}
