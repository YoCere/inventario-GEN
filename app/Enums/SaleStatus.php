<?php

namespace App\Enums;

enum SaleStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Reservado',
            self::COMPLETED => 'Completado',
            self::CANCELLED => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'text-yellow-600 bg-yellow-100',
            self::COMPLETED => 'text-green-600 bg-green-100',
            self::CANCELLED => 'text-red-600 bg-red-100',
        };
    }
}
