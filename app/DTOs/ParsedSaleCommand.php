<?php

namespace App\DTOs;

use App\Enums\PaymentMethod;

/**
 * Resultado del parseo determinista de una orden de venta.
 * Exactamente uno de $productQuery (por nombre) o $position (posicional) es no-nulo.
 */
final class ParsedSaleCommand
{
    public function __construct(
        public readonly int $quantity,
        public readonly ?int $unitPriceCents,
        public readonly ?int $totalPriceCents,
        public readonly PaymentMethod $method,
        public readonly ?string $productQuery,
        public readonly ?int $position,
    ) {}
}
