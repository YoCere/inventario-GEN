<?php

namespace App\Enums;

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

    public function color(): string
    {
        return match($this) {
            self::DRAFT => 'text-gray-600 bg-gray-50 border-gray-200',
            self::ORDERED => 'text-sky-700 bg-sky-50 border-sky-200',
            self::RECEIVED => 'text-green-700 bg-green-50 border-green-200',
            self::PAID => 'text-emerald-700 bg-emerald-50 border-emerald-200',
            self::CANCELLED => 'text-red-700 bg-red-50 border-red-200',
        };
    }
}
