<?php

namespace App\Enums;

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
}
