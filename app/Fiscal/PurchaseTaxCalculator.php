<?php

namespace App\Fiscal;

use App\Models\Setting;

/** Desglose fiscal de una compra: solo IVA (crédito fiscal). IT es solo de ventas. Centavos. */
class PurchaseTaxCalculator
{
    /** @return array{taxable_base:int, iva_amount:int} */
    public function forTotal(int $totalCents): array
    {
        $ivaRate = (float) Setting::get('tax_iva_rate', '0');
        return [
            'taxable_base' => max(0, $totalCents),
            'iva_amount'   => (int) round($totalCents * $ivaRate / 100),
        ];
    }
}
