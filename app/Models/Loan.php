<?php

namespace App\Models;

use App\Enums\LoanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Loan extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'lender', 'code', 'principal', 'annual_rate_pct', 'term_months', 'start_date',
        'payment_day', 'liability_account_code', 'interest_account_code', 'payment_account_code',
        'status', 'is_opening', 'outstanding_balance', 'disbursement_entry_id',
    ];

    protected $attributes = [
        'status' => 'active',
        'is_opening' => false,
        'outstanding_balance' => 0,
        'liability_account_code' => '2.2.01',
        'interest_account_code' => '6.3',
        'payment_account_code' => '1.1.02',
    ];

    protected $casts = [
        'start_date' => 'date',
        'principal' => 'integer',
        'annual_rate_pct' => 'decimal:4',
        'term_months' => 'integer',
        'payment_day' => 'integer',
        'is_opening' => 'boolean',
        'outstanding_balance' => 'integer',
        'status' => LoanStatus::class,
    ];

    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class);
    }
}
