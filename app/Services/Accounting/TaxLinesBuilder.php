<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\Setting;
use RuntimeException;

/**
 * Arma las líneas fiscales de un asiento a partir de montos ya calculados (F0).
 * NO ajusta las líneas base (Ventas/Inventario): eso lo hace el Service reduciéndolas
 * al neto. El builder solo emite las líneas de impuesto con los montos exactos.
 */
class TaxLinesBuilder
{
    /**
     * Venta con factura: DF-IVA (crédito), IT gasto (débito), IT por pagar (crédito).
     * @return array<int, array{chart_of_account_id:int,debit_amount:int,credit_amount:int,description:string}>
     */
    public function saleTaxLines(int $ivaCents, int $itCents): array
    {
        $lines = [];
        if ($ivaCents > 0) {
            $lines[] = $this->line(Setting::get('accounting_df_iva_code', '2.1.11'), 0, $ivaCents, 'Débito Fiscal IVA');
        }
        if ($itCents > 0) {
            $lines[] = $this->line(Setting::get('accounting_it_expense_code', '6.7'), $itCents, 0, 'Impuesto a las Transacciones');
            $lines[] = $this->line(Setting::get('accounting_it_payable_code', '2.1.12'), 0, $itCents, 'IT por Pagar');
        }
        return $lines;
    }

    /** Compra con factura: CF-IVA (débito). Sin IT (IT es solo ventas). */
    public function purchaseTaxLines(int $ivaCents): array
    {
        if ($ivaCents <= 0) {
            return [];
        }
        return [$this->line(Setting::get('accounting_cf_iva_code', '1.1.05'), $ivaCents, 0, 'Crédito Fiscal IVA')];
    }

    private function line(string $code, int $debit, int $credit, string $desc): array
    {
        return [
            'chart_of_account_id' => $this->accountId($code),
            'debit_amount' => $debit,
            'credit_amount' => $credit,
            'description' => $desc,
        ];
    }

    private function accountId(string $code): int
    {
        $account = ChartOfAccount::query()
            ->where('code', $code)->where('is_active', true)->where('allows_posting', true)->first();
        if (! $account) {
            throw new RuntimeException("No existe cuenta contable activa/imputable con código {$code}.");
        }
        return $account->id;
    }
}
