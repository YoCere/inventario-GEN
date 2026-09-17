<?php

namespace App\Enums;

use App\Support\Ui\Tone;

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

    /** Tono de color del estado (ver App\Support\Ui\Tone). */
    public function tone(): string
    {
        return match ($this) {
            self::Active => Tone::SUCCESS,
            self::FullyDepreciated => Tone::INFO,
            self::Disposed => Tone::DANGER,
            self::NotDepreciable => Tone::NEUTRAL,
        };
    }
}
