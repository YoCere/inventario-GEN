<?php

namespace App\Fiscal\Siat\Dtos;

use Carbon\CarbonImmutable;

/** Código Único de Facturación Diaria (vigencia 24 h), por sucursal + punto de venta. */
readonly class Cufd
{
    public function __construct(
        public string $value,
        public int $sucursal,
        public int $puntoVenta,
        public CarbonImmutable $expiresAt,
        public ?string $codigoControl = null,
        public ?string $direccion = null,
    ) {}
}
