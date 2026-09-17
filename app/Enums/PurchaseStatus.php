<?php

namespace App\Enums;

use App\Support\Ui\Tone;

enum PurchaseStatus: string
{
    case DRAFT = 'draft';
    case ORDERED = 'ordered';
    case RECEIVED = 'received';
    case PAID = 'paid';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::DRAFT => 'Borrador',
            self::ORDERED => 'Pedido',
            self::RECEIVED => 'Recibido',
            self::PAID => 'Pagado',
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
            self::DRAFT => Tone::NEUTRAL,
            self::ORDERED => Tone::WARNING,
            self::RECEIVED => Tone::SUCCESS,
            self::PAID => Tone::SUCCESS,
            self::CANCELLED => Tone::DANGER,
        };
    }
}
