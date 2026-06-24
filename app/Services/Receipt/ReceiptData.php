<?php

namespace App\Services\Receipt;

readonly class ReceiptLine
{
    public function __construct(
        public string $rawName,
        public int $quantity,
        public int $unitPrice, // céntimos
    ) {}
}

readonly class ReceiptData
{
    /** @param ReceiptLine[] $lines */
    public function __construct(
        public ?string $purchaseDate, // 'Y-m-d' o null
        public ?string $supplierName,
        public array $lines,
    ) {}
}
