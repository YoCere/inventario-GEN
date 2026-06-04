<?php

namespace App\Services\Accounting;

use App\Enums\AccountNormalBalance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LedgerBalanceService
{
    /**
     * Saldo acumulado por cuenta hasta $asOf (inclusive).
     * $includeAdjustments=false → solo entry_type 'normal'.
     *
     * @return Collection<int, object> con propiedades: chart_of_account_id, code, name,
     *   account_type, normal_balance, debit (int), credit (int), balance (int)
     */
    public function balancesAt(string $asOf, bool $includeAdjustments = true): Collection
    {
        return $this->aggregate(null, $asOf, $includeAdjustments, adjustmentsOnly: false);
    }

    public function movementsBetween(string $from, string $to, bool $adjustmentsOnly = false): Collection
    {
        return $this->aggregate($from, $to, includeAdjustments: true, adjustmentsOnly: $adjustmentsOnly);
    }

    private function aggregate(?string $from, string $to, bool $includeAdjustments, bool $adjustmentsOnly): Collection
    {
        $query = DB::table('ledger_account_daily as d')
            ->join('chart_of_accounts as c', 'c.id', '=', 'd.chart_of_account_id')
            ->whereDate('d.movement_date', '<=', $to)
            ->selectRaw('d.chart_of_account_id, c.code, c.name, c.account_type, c.normal_balance,
                SUM(d.debit_total) as debit, SUM(d.credit_total) as credit')
            ->groupBy('d.chart_of_account_id', 'c.code', 'c.name', 'c.account_type', 'c.normal_balance')
            ->orderBy('c.code');

        if ($from !== null) {
            $query->whereDate('d.movement_date', '>=', $from);
        }
        if ($adjustmentsOnly) {
            $query->where('d.entry_type', 'ajuste');
        } elseif (!$includeAdjustments) {
            $query->where('d.entry_type', 'normal');
        }

        return $query->get()->map(function ($row) {
            $debit = (int) $row->debit;
            $credit = (int) $row->credit;
            $row->debit = $debit;
            $row->credit = $credit;
            $row->balance = $row->normal_balance === AccountNormalBalance::Debit->value
                ? $debit - $credit
                : $credit - $debit;
            $row->chart_of_account_id = (int) $row->chart_of_account_id;
            return $row;
        });
    }
}
