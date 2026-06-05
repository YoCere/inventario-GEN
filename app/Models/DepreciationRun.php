<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepreciationRun extends Model
{
    protected $fillable = ['fixed_asset_id', 'year_month', 'amount', 'journal_entry_id', 'posted_at'];

    protected $casts = ['amount' => 'integer', 'posted_at' => 'datetime'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }
}
