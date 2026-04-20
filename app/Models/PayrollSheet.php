<?php

namespace App\Models;

use App\Enums\PayrollSheetStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PayrollSheet extends Model
{
    use HasFactory;

    protected $fillable = [
        'sheet_number',
        'period_month',
        'payment_date',
        'description',
        'status',
        'total_earned',
        'total_deductions',
        'net_payable',
        'total_employer_contributions',
        'total_employer_cost',
        'accounting_period_id',
        'journal_entry_id',
        'created_by',
        'posted_by',
        'posted_at',
    ];

    protected $casts = [
        'period_month' => 'date',
        'payment_date' => 'date',
        'posted_at' => 'datetime',
        'status' => PayrollSheetStatus::class,
        'total_earned' => 'integer',
        'total_deductions' => 'integer',
        'net_payable' => 'integer',
        'total_employer_contributions' => 'integer',
        'total_employer_cost' => 'integer',
        'accounting_period_id' => 'integer',
        'journal_entry_id' => 'integer',
        'created_by' => 'integer',
        'posted_by' => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PayrollSheetItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}

