<?php

namespace App\Enums;

use App\Support\Ui\Tone;

enum AccountingPeriodStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierto',
            self::Closed => 'Cerrado',
        };
    }

    /** Tono de color del estado (ver App\Support\Ui\Tone). */
    public function tone(): string
    {
        return match ($this) {
            self::Open => Tone::SUCCESS,
            self::Closed => Tone::NEUTRAL,
        };
    }
}
