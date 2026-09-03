<?php

namespace App\Enums;

enum JournalEntryType: string
{
    case Normal = 'normal';
    case Ajuste = 'ajuste';
    case Apertura = 'apertura';
    case Cierre = 'cierre';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Ajuste => 'Ajuste',
            self::Apertura => 'Apertura',
            self::Cierre => 'Cierre',
        };
    }
}
