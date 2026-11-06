<?php

namespace App\Fiscal\Invoicing;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\Setting;

class InvoiceDraftFactory
{
    public function fromSale(Sale $sale): InvoiceDraft
    {
        if ($sale->status !== SaleStatus::COMPLETED) {
            throw new \DomainException("La venta #{$sale->id} no está completada; no se puede facturar.");
        }
        $sale->load([
            'items.product' => fn ($q) => $q->withTrashed(),
            'items.product.unit',
            'customer' => fn ($q) => $q->withTrashed(),
        ]);
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

        foreach ($lines as $line) {
            if ($line->net() < 0) {
                throw new \DomainException("La venta #{$sale->id} produce una línea con importe negativo.");
            }
        }
        if (array_sum(array_map(fn ($l) => $l->net(), $lines)) !== (int) $sale->total) {
            throw new \DomainException("La venta #{$sale->id}: el total no coincide con la suma de sus líneas.");
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
     * Reparte $amount proporcional a $weights (método del mayor resto, solo enteros).
     * Con amount <= suma de pesos, ninguna parte supera su peso.
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
        $remainders = [];
        foreach ($weights as $i => $w) {
            $shares[$i] = intdiv($amount * $w, $sum);
            $remainders[$i] = ($amount * $w) % $sum;
        }
        $left = $amount - array_sum($shares);
        $order = array_keys($remainders);
        usort($order, fn ($a, $b) => $remainders[$b] <=> $remainders[$a] ?: $a <=> $b);
        foreach (array_slice($order, 0, $left) as $i) {
            $shares[$i]++;
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
