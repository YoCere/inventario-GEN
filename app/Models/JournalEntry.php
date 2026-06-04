<?php

namespace App\Models;

use App\Enums\JournalEntryStatus;
use App\Enums\JournalEntryType;
use App\Enums\VoucherType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class JournalEntry extends Model
{
    use HasFactory;

    protected $table = 'journal_entries';

    protected $fillable = [
        'entry_number',
        'entry_date',
        'accounting_period_id',
        'description',
        'source_type',
        'source_id',
        'voucher_type',
        'voucher_number',
        'entry_type',
        'status',
        'posted_at',
        'posted_by',
        'reversed_entry_id',
        'created_by',
    ];

    protected $casts = [
        'entry_date'   => 'date',
        'status'       => JournalEntryStatus::class,
        'voucher_type' => VoucherType::class,
        'entry_type'   => JournalEntryType::class,
        'posted_at'    => 'datetime',
    ];

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_entry_id');
    }

    public function scopeMovimientos(Builder $query): Builder
    {
        return $query->where('entry_type', JournalEntryType::Normal);
    }

    public function scopeAjustes(Builder $query): Builder
    {
        return $query->where('entry_type', JournalEntryType::Ajuste);
    }
}
