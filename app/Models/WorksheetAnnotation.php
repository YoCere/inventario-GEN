<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorksheetAnnotation extends Model
{
    protected $fillable = [
        'accounting_period_id', 'chart_of_account_id', 'manual_note', 'action_status', 'user_id',
    ];
}
