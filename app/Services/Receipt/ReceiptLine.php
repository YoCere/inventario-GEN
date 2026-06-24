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
