<?php

namespace App\Enums;

use App\Support\Ui\Tone;

enum JournalEntryStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Posted => 'Contabilizado',
            self::Reversed => 'Revertido',
        };
    }

    /** Tono de color del estado (ver App\Support\Ui\Tone). */
    public function tone(): string
    {
        return match ($this) {
            self::Draft => Tone::NEUTRAL,
            self::Posted => Tone::SUCCESS,
            self::Reversed => Tone::DANGER,
        };
    }
}
