<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetLine extends Model
{
    protected $fillable = ['budget_id', 'chart_of_account_code', 'name', 'line_type', 'base_amount', 'growth_pct'];

    protected $casts = ['base_amount' => 'integer', 'growth_pct' => 'decimal:4'];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }
}
