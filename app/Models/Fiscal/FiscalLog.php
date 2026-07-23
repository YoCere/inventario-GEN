<?php

namespace App\Models\Fiscal;

use Illuminate\Database\Eloquent\Model;

class FiscalLog extends Model
{
    protected $fillable = [
        'service',
        'environment',
        'request',
        'response',
        'success',
        'error_code',
    ];

    protected $casts = [
        'success' => 'boolean',
    ];
}
