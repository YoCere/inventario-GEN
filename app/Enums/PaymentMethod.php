<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case CASH = 'cash';
    case TRANSFER = 'transfer';
    case QR = 'qr';
    case CARD = 'card';

    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Efectivo',
            self::TRANSFER => 'Transferencia',
            self::QR => 'QR',
            self::CARD => 'Tarjeta',
        };
    }

    /**
     * Código de la paramétrica "método de pago" del SIN. Los valores exactos salen
     * del catálogo oficial (sincronizado en F1); acá van los de uso común. Es la
     * única fuente del mapeo — F1 lo lee para armar la factura.
     */
    public function siatCode(): int
    {
        return match ($this) {
            self::CASH => 1,
            self::CARD => 2,
            self::QR => 7,
            self::TRANSFER => 1,
        };
    }
}
