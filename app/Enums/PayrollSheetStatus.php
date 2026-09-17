<?php

namespace App\Enums;

use App\Support\Ui\Tone;

enum PayrollSheetStatus: string
{
    case DRAFT = 'draft';
    case POSTED = 'posted';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Borrador',
            self::POSTED => 'Contabilizado',
        };
    }

    /** Tono de color del estado (ver App\Support\Ui\Tone). */
    public function tone(): string
    {
        return match ($this) {
            self::DRAFT => Tone::NEUTRAL,
            self::POSTED => Tone::SUCCESS,
        };
    }
}
