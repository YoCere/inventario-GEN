<?php

namespace App\Fiscal;

use App\Models\Setting;

/**
 * Desglose fiscal de una venta (caso general, sin ICE/IEHD/exentos). En Bolivia el
 * IVA va incluido en el precio: la base para débito fiscal es el total (menos
 * exentos/giftcard, hoy 0), el débito fiscal es 13% de esa base, y el IT 3% del total.
 *
 * OJO tributario: la fórmula exacta la confirma el contador del contribuyente. Es el
 * caso general y es sobrescribible; la validez fiscal no es responsabilidad del código.
 *
 * Montos en centavos (enteros).
 */
class SaleTaxCalculator
{
    /**
     * @return array{taxable_base:int, iva_amount:int, it_amount:int}
     */
    public function forTotal(int $totalCents, int $exemptCents = 0, int $giftCardCents = 0): array
    {
        $base = max(0, $totalCents - $exemptCents - $giftCardCents);

        $ivaRate = (float) Setting::get('tax_iva_rate', '0');
        $itRate  = (float) Setting::get('tax_it_rate', '0');

        return [
            'taxable_base' => $base,
            'iva_amount'   => (int) round($base * $ivaRate / 100),
            'it_amount'    => (int) round($totalCents * $itRate / 100),
        ];
    }
}
