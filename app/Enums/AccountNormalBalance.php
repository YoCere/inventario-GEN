<?php

namespace App\Enums;

enum AccountNormalBalance: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Debit => 'Deudor',
            self::Credit => 'Acreedor',
        };
    }
}
