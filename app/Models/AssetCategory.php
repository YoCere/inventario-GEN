<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetCategory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'useful_life_months', 'annual_rate_pct', 'is_deferred',
        'ppe_account_code', 'accumulated_account_code', 'expense_account_code', 'is_active',
    ];

    protected $casts = [
        'is_deferred' => 'boolean',
        'is_active' => 'boolean',
        'useful_life_months' => 'integer',
        'annual_rate_pct' => 'decimal:2',
    ];

    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class);
    }
}
