<?php

namespace App\Fiscal\Invoicing;

/** Montos en centavos. discount = descuento TOTAL de la línea (ítem × cantidad + parte del global). */
readonly class InvoiceDraftLine
{
    public function __construct(
        public ?int $productId,
        public string $description,
        public int $quantity,
        public int $unitPrice,
        public int $discount,
        public ?string $economicActivity,
        public ?string $sinProductCode,
        public ?string $sinUnitCode,
    ) {}

    public function net(): int
    {
        return $this->unitPrice * $this->quantity - $this->discount;
    }
}
