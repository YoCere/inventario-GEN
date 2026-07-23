<?php

namespace App\Fiscal;

/**
 * Identidad tributaria del comprador para una factura. Objeto de valor inmutable,
 * framework-agnóstico: lo llena el POS hoy y la tienda/bot después, sin refactor.
 * NO verifica el NIT contra el SIN (eso es F1); solo valida forma.
 */
readonly class BillingIdentity
{
    public function __construct(
        public string $docType,
        public string $docNumber,
        public ?string $complement = null,
        public ?string $businessName = null,
    ) {}

    public function isComplete(): bool
    {
        return trim($this->docType) !== '' && trim($this->docNumber) !== '';
    }

    /** @return array<string,?string> */
    public function toArray(): array
    {
        return [
            'doc_type' => $this->docType,
            'doc_number' => $this->docNumber,
            'doc_complement' => $this->complement,
            'business_name' => $this->businessName,
        ];
    }
}
