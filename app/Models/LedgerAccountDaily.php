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

    // Las lecturas usan Eloquent (cast 'date'); las escrituras incrementales
    // las hace UpdateLedgerSnapshot con DB::table() para evitar que el cast
    // convierta la fecha y rompa el lookup por unique key. No "arreglar" el
    // listener a Eloquent sin resolver eso primero.
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
