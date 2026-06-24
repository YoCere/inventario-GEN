<?php

namespace App\Services\Receipt;

readonly class ReceiptData
{
    /** @param ReceiptLine[] $lines */
    public function __construct(
        public ?string $purchaseDate, // 'Y-m-d' o null
        public ?string $supplierName,
        public array $lines,
    ) {}
}
