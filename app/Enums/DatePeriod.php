<?php

namespace App\Enums;

enum DatePeriod: string
{
    case TODAY = 'today';
    case YESTERDAY = 'yesterday';
    case THIS_WEEK = 'this_week';
    case THIS_MONTH = 'this_month';
    case LAST_MONTH = 'last_month';
    case CUSTOM = 'custom';

    public function label(): string
    {
        return match($this) {
            self::TODAY => 'Hoy',
            self::YESTERDAY => 'Ayer',
            self::THIS_WEEK => 'Esta semana',
            self::THIS_MONTH => 'Este mes',
            self::LAST_MONTH => 'Mes pasado',
            self::CUSTOM => 'Elegir fechas',
        };
    }
}
