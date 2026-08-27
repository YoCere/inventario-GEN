<?php

namespace App\Enums;

enum VoucherType: string
{
    case Ingreso  = 'ingreso';
    case Egreso   = 'egreso';
    case Traspaso = 'traspaso';
    case Apertura = 'apertura';

    public function label(): string
    {
        return match ($this) {
            self::Ingreso  => 'Comprobante de Ingreso',
            self::Egreso   => 'Comprobante de Egreso',
            self::Traspaso => 'Comprobante de Traspaso',
            self::Apertura => 'Comprobante de Apertura',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Ingreso  => 'INGRESO',
            self::Egreso   => 'EGRESO',
            self::Traspaso => 'TRASPASO',
            self::Apertura => 'APERTURA',
        };
    }
}
