<?php

namespace App\Models;

use App\Enums\InstallmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanInstallment extends Model
{
    protected $fillable = [
        'loan_id', 'number', 'due_date', 'payment_amount', 'interest_amount',
        'principal_amount', 'balance_after', 'status', 'paid_date', 'journal_entry_id',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $casts = [
        'due_date' => 'date',
        'paid_date' => 'date',
        'payment_amount' => 'integer',
        'interest_amount' => 'integer',
        'principal_amount' => 'integer',
        'balance_after' => 'integer',
        'status' => InstallmentStatus::class,
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
