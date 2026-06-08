<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Budget extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'base_from', 'base_to', 'years', 'growth_pct',
        'discount_rate_pct', 'iue_rate_pct', 'is_active', 'created_by',
    ];

    protected $attributes = ['years' => 5, 'growth_pct' => 0, 'discount_rate_pct' => 12, 'iue_rate_pct' => 25, 'is_active' => true];

    protected $casts = [
        'base_from' => 'date', 'base_to' => 'date',
        'years' => 'integer',
        'growth_pct' => 'decimal:4', 'discount_rate_pct' => 'decimal:4', 'iue_rate_pct' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }
}
