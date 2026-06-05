<?php

namespace App\Enums;

enum InstallmentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Pendiente',
            self::Paid      => 'Pagada',
            self::Cancelled => 'Cancelada',
        };
    }
}
