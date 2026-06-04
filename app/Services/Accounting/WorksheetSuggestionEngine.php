<?php

namespace App\Services\Accounting;

class WorksheetSuggestionEngine
{
    /**
     * @param array{account_type:string, is_liquidity:bool, saldo:int,
     *   porcentaje_total:?float, variacion_pct:?float} $row
     * @param array{liquidity_target:int, high_expense_pct:float, variation_pct:float} $ctx
     */
    public function evaluate(array $row, array $ctx): string
    {
        if (!empty($row['is_liquidity'])) {
            if ($row['saldo'] > $ctx['liquidity_target']) {
                return 'Excedente de liquidez: considerar fondo/ahorro a 30 días.';
            }
            return 'Liquidez por debajo del mínimo (Bs ' . number_format($ctx['liquidity_target'] / 100, 2) . ').';
        }

        if (in_array($row['account_type'], ['expense', 'cost'], true)
            && $row['porcentaje_total'] !== null
            && $row['porcentaje_total'] >= $ctx['high_expense_pct']) {
            return 'Gasto representa ' . round($row['porcentaje_total']) . '% del total: revisar/negociar.';
        }

        if ($row['variacion_pct'] !== null && abs($row['variacion_pct']) >= $ctx['variation_pct']) {
            return 'Variación brusca vs mes anterior (' . round($row['variacion_pct']) . '%): revisar.';
        }

        if ($row['account_type'] === 'liability' && $row['saldo'] > 0) {
            return 'Pasivo pendiente: programar pago antes de vencimiento.';
        }

        return '';
    }
}
