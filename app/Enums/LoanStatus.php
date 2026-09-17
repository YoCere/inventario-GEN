<?php

namespace App\Enums;

use App\Support\Ui\Tone;

enum LoanStatus: string
{
    case Active = 'active';
    case PaidOff = 'paid_off';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Vigente',
            self::PaidOff => 'Pagado',
        };
    }

    /** Tono de color del estado (ver App\Support\Ui\Tone). */
    public function tone(): string
    {
        return match ($this) {
            self::Active => Tone::SUCCESS,
            self::PaidOff => Tone::INFO,
        };
    }
}
