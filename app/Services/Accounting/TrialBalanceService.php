<?php

namespace App\Services\Accounting;

use App\Enums\AccountNormalBalance;
use App\Models\AccountingPeriod;
use Illuminate\Support\Facades\DB;

class TrialBalanceService
{
    public function __construct(private LedgerBalanceService $balances)
    {
    }

    /**
     * @return array{filas: array<int, array<string,mixed>>, totales: array<string,int>, cuadra: bool}
     */
    public function build(AccountingPeriod $period, bool $adjusted): array
    {
        $to = $period->end_date->toDateString();
        $from = $period->start_date->toDateString();

        // Sumas = movimientos del periodo; Saldos = acumulado hasta fin de periodo.
        $sumas = DB::table('ledger_account_daily as d')
            ->whereDate('d.movement_date', '>=', $from)
            ->whereDate('d.movement_date', '<=', $to)
            ->when(!$adjusted, fn ($q) => $q->where('d.entry_type', 'normal'))
            ->selectRaw('d.chart_of_account_id, SUM(d.debit_total) as sd, SUM(d.credit_total) as sc')
            ->groupBy('d.chart_of_account_id')
            ->get()->keyBy('chart_of_account_id');

        $saldos = $this->balances->balancesAt($to, includeAdjustments: $adjusted);

        $filas = [];
        $tot = ['sumas_debe' => 0, 'sumas_haber' => 0, 'saldo_deudor' => 0, 'saldo_acreedor' => 0];

        foreach ($saldos as $s) {
            $sumDebe  = (int) (optional($sumas->get($s->chart_of_account_id))->sd ?? 0);
            $sumHaber = (int) (optional($sumas->get($s->chart_of_account_id))->sc ?? 0);

            $deudor = 0;
            $acreedor = 0;
            if ($s->balance >= 0) {
                if ($s->normal_balance === AccountNormalBalance::Debit->value) {
                    $deudor = $s->balance;
                } else {
                    $acreedor = $s->balance;
                }
            } else {
                // saldo negativo: invierte de lado
                if ($s->normal_balance === AccountNormalBalance::Debit->value) {
                    $acreedor = -$s->balance;
                } else {
                    $deudor = -$s->balance;
                }
            }

            $filas[] = [
                'code' => $s->code, 'name' => $s->name, 'account_type' => $s->account_type,
                'sumas_debe' => $sumDebe, 'sumas_haber' => $sumHaber,
                'saldo_deudor' => $deudor, 'saldo_acreedor' => $acreedor,
            ];
            $tot['sumas_debe'] += $sumDebe;
            $tot['sumas_haber'] += $sumHaber;
            $tot['saldo_deudor'] += $deudor;
            $tot['saldo_acreedor'] += $acreedor;
        }

        $cuadra = $tot['saldo_deudor'] === $tot['saldo_acreedor']
            && $tot['sumas_debe'] === $tot['sumas_haber'];

        return ['filas' => $filas, 'totales' => $tot, 'cuadra' => $cuadra];
    }
}
