<?php

namespace App\Enums;

use App\Support\Ui\Tone;

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

    /** @deprecated Usa <x-status-badge> o Tone::badge($this->tone()). */
    public function color(): string
    {
        return Tone::badge($this->tone());
    }

    /** Tono de color del estado (ver App\Support\Ui\Tone). */
    public function tone(): string
    {
        return match ($this) {
            self::PENDING => Tone::WARNING,
            self::COMPLETED => Tone::SUCCESS,
            self::CANCELLED => Tone::DANGER,
        };
    }
}
