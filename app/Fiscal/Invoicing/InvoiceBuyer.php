<?php

namespace App\Fiscal\Invoicing;

readonly class InvoiceBuyer
{
    public function __construct(
        public string $docType,
        public string $docNumber,
        public ?string $complement,
        public string $name,
        public ?string $code,
    ) {}
}
