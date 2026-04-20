<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\AccountNormalBalance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ChartOfAccount extends Model
{
    use HasFactory;

    protected $table = 'chart_of_accounts';

    protected $fillable = [
        'code',
        'name',
        'level',
        'parent_id',
        'account_type',
        'normal_balance',
        'allows_posting',
        'is_active',
        'description',
    ];

    protected $casts = [
        'level' => 'integer',
        'account_type' => AccountType::class,
        'normal_balance' => AccountNormalBalance::class,
        'allows_posting' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function journalEntryLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }
}
