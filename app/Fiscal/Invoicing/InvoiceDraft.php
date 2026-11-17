<?php

namespace App\Fiscal\Invoicing;

/** Factura lista para cualquier proveedor. No sabe nada de Falmus. Montos en centavos. */
readonly class InvoiceDraft
{
    /** @param list<InvoiceDraftLine> $lines */
    public function __construct(
        public int $saleId,
        public int $documentSector,
        public ?InvoiceBuyer $buyer,
        public int $paymentMethodCode,
        public array $lines,
        public int $total,
    ) {}
}
