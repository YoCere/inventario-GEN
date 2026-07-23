<?php

namespace App\Models\Fiscal;

use Illuminate\Database\Eloquent\Model;

class FiscalCatalogEntry extends Model
{
    protected $fillable = [
        'catalog_type',
        'code',
        'description',
        'extra',
        'synced_at',
    ];

    protected $casts = [
        'extra' => 'array',
        'synced_at' => 'datetime',
    ];
}
