<?php

namespace App\Models\Fiscal;

use Illuminate\Database\Eloquent\Model;

class FiscalCufd extends Model
{
    protected $table = 'fiscal_cufd';

    protected $fillable = [
        'value',
        'sucursal',
        'punto_venta',
        'codigo_control',
        'direccion',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function scopeValidFor($query, int $sucursal, int $puntoVenta)
    {
        return $query->where('sucursal', $sucursal)
            ->where('punto_venta', $puntoVenta)
            ->where('expires_at', '>', now())
            ->latest('expires_at');
    }
}
