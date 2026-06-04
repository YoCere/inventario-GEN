<?php

namespace App\Services\Accounting;

use Illuminate\Support\Carbon;

class FinancialReadModel
{
    public function __construct(private LedgerBalanceService $balances)
    {
    }

    public function statusAt(string $date): array
    {
        $b = $this->balances->balancesAt($date, includeAdjustments: true);
        $sum = fn (string $type) => (int) $b->where('account_type', $type)->sum('balance');

        return [
            'fecha_corte' => $date,
            'activos_bs'    => $this->bs($sum('asset')),
            'pasivos_bs'    => $this->bs($sum('liability')),
            'patrimonio_bs' => $this->bs($sum('equity')),
            'resultado_acumulado_bs' => $this->bs($sum('income') - $sum('expense') - $sum('cost')),
        ];
    }

    public function incomeStatement(string $from, string $to): array
    {
        $delta = $this->periodDeltas($from, $to);

        $ingresos = $delta('income');
        $costos   = $delta('cost');
        $gastos   = $delta('expense');
        $utilidad = $ingresos - $costos - $gastos;

        return [
            'desde' => $from, 'hasta' => $to,
            'ingresos' => $this->bs($ingresos),
            'costos'   => $this->bs($costos),
            'gastos'   => $this->bs($gastos),
            'utilidad_neta' => $this->bs($utilidad),
        ];
    }

    public function expensesByCategory(string $from, string $to): array
    {
        return $this->byCategory($from, $to, ['expense', 'cost']);
    }

    public function incomeByCategory(string $from, string $to): array
    {
        return $this->byCategory($from, $to, ['income']);
    }

    public function balanceSheet(string $asOf): array
    {
        $b = $this->balances->balancesAt($asOf, includeAdjustments: true);
        $map = fn ($r) => ['cuenta' => $r->name, 'codigo' => $r->code, 'monto_bs' => $this->bs((int) $r->balance)];

        return [
            'fecha' => $asOf,
            'activos'    => $b->where('account_type', 'asset')->map($map)->values()->all(),
            'pasivos'    => $b->where('account_type', 'liability')->map($map)->values()->all(),
            'patrimonio' => $b->where('account_type', 'equity')->map($map)->values()->all(),
        ];
    }

    /**
     * Devuelve una clausura $delta(string $type): int con la variación (centavos)
     * del tipo de cuenta entre el día anterior a $from y $to.
     */
    private function periodDeltas(string $from, string $to): \Closure
    {
        $atTo   = $this->balances->balancesAt($to, includeAdjustments: true);
        $atFrom = $this->balances->balancesAt(
            Carbon::parse($from)->subDay()->toDateString(), includeAdjustments: true
        );

        return fn (string $type) => (int) $atTo->where('account_type', $type)->sum('balance')
            - (int) $atFrom->where('account_type', $type)->sum('balance');
    }

    private function byCategory(string $from, string $to, array $types): array
    {
        $atTo   = $this->balances->balancesAt($to, includeAdjustments: true);
        $atFrom = $this->balances->balancesAt(
            Carbon::parse($from)->subDay()->toDateString(), includeAdjustments: true
        )->keyBy('chart_of_account_id');

        $rows = $atTo->whereIn('account_type', $types)->map(function ($r) use ($atFrom) {
            $prev = (int) (optional($atFrom->get($r->chart_of_account_id))->balance ?? 0);
            return ['cuenta' => $r->name, 'codigo' => $r->code, 'monto_bs' => $this->bs((int) $r->balance - $prev)];
        })->filter(fn ($r) => $r['monto_bs'] != 0.0)->values();

        $total = $rows->sum('monto_bs');
        return $rows->map(fn ($r) => $r + ['porcentaje' => $total > 0 ? round($r['monto_bs'] / $total * 100, 1) : 0])->all();
    }

    private function bs(int $centavos): float
    {
        return round($centavos / 100, 2);
    }
}
