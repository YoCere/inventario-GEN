<?php

namespace App\Models\Fiscal;

use Illuminate\Database\Eloquent\Model;

class FiscalCuis extends Model
{
    protected $table = 'fiscal_cuis';

    protected $fillable = [
        'value',
        'sucursal',
        'punto_venta',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
