<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerAccountDaily extends Model
{
    protected $table = 'ledger_account_daily';

    protected $fillable = [
        'chart_of_account_id',
        'movement_date',
        'entry_type',
        'debit_total',
        'credit_total',
    ];

    protected $casts = [
        'movement_date' => 'date',
        'debit_total'   => 'integer',
        'credit_total'  => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id');
    }
}
