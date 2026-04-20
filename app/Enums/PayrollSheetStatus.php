<?php

namespace App\Enums;

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
}

