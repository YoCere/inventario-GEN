<?php

namespace App\Fiscal\Invoicing;

use App\Models\Sale;
use App\Models\Setting;

class InvoiceDraftFactory
{
    public function fromSale(Sale $sale): InvoiceDraft
    {
        $sale->loadMissing('items.product.unit', 'customer');
        $defaultActivity = Setting::get('fiscal_default_activity') ?: null;

        $items = $sale->items->values();
        $lineNets = $items->map(fn ($i) => (int) $i->final_price * (int) $i->quantity)->all();
        $globalShares = $this->prorate((int) $sale->global_discount, $lineNets);

        $lines = [];
        foreach ($items as $idx => $item) {
            $p = $item->product;
            $lines[] = new InvoiceDraftLine(
                productId: $item->product_id,
                description: $p?->name ?? 'Producto',
                quantity: (int) $item->quantity,
                unitPrice: (int) $item->unit_price,
                discount: (int) $item->discount * (int) $item->quantity + $globalShares[$idx],
                economicActivity: $p?->economic_activity ?: $defaultActivity,
                sinProductCode: $p?->sin_code ?: null,
                sinUnitCode: $p?->unit?->sin_code ?: null,
            );
        }

        return new InvoiceDraft(
            saleId: $sale->id,
            documentSector: 1,
            buyer: $this->buyer($sale),
            paymentMethodCode: $sale->payment_method->siatCode(),
            lines: $lines,
            total: (int) $sale->total,
        );
    }

    /**
     * Reparte $amount proporcional a $weights; el sobrante por redondeo va a la última línea.
     *
     * @param list<int> $weights
     * @return list<int>
     */
    private function prorate(int $amount, array $weights): array
    {
        $n = count($weights);
        $sum = array_sum($weights);
        if ($amount === 0 || $n === 0 || $sum === 0) {
            return array_fill(0, $n, 0);
        }
        $shares = [];
        $assigned = 0;
        foreach ($weights as $i => $w) {
            $share = $i === $n - 1 ? $amount - $assigned : intdiv($amount * $w, $sum);
            $shares[] = $share;
            $assigned += $share;
        }

        return $shares;
    }

    private function buyer(Sale $sale): ?InvoiceBuyer
    {
        $c = $sale->customer;
        if (! $c || ! $c->hasBillingIdentity()) {
            return null;
        }
        $id = $c->billingIdentity();

        return new InvoiceBuyer(
            docType: $id->docType,
            docNumber: $id->docNumber,
            complement: $id->complement ?: null,
            name: $id->businessName ?: $c->name,
            code: 'C-'.$c->id,
        );
    }
}
