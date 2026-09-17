<?php

namespace App\Enums;

use App\Support\Ui\Tone;

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

    /** Tono de color del estado (ver App\Support\Ui\Tone). */
    public function tone(): string
    {
        return match ($this) {
            self::Pending => Tone::WARNING,
            self::Paid => Tone::SUCCESS,
            self::Cancelled => Tone::DANGER,
        };
    }
}
