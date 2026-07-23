<?php

namespace App\Fiscal\Siat\Dtos;

use Carbon\CarbonImmutable;

/** Código Único de Inicio de Sistemas (vigencia ~365 días). */
readonly class Cuis
{
    public function __construct(
        public string $value,
        public CarbonImmutable $expiresAt,
    ) {}
}
