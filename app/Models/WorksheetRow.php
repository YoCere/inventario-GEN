<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorksheetRow extends Model
{
    protected $guarded = [];

    protected $casts = ['generated_at' => 'datetime'];
}
