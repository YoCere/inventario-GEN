<?php

namespace App\Enums;

enum FixedAssetStatus: string
{
    case Active = 'active';
    case FullyDepreciated = 'fully_depreciated';
    case Disposed = 'disposed';
    case NotDepreciable = 'not_depreciable';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activo',
            self::FullyDepreciated => 'Totalmente depreciado',
            self::Disposed => 'Dado de baja',
            self::NotDepreciable => 'No depreciable',
        };
    }
}
