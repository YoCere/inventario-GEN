<?php

namespace App\Enums;

enum JournalEntryType: string
{
    case Normal = 'normal';
    case Ajuste = 'ajuste';
    case Apertura = 'apertura';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Ajuste => 'Ajuste',
            self::Apertura => 'Apertura',
        };
    }
}
