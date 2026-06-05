<?php

namespace App\Models;

use App\Enums\FixedAssetStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FixedAsset extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'status' => 'active',
        'residual_value' => 0,
        'accumulated_depreciation' => 0,
        'is_opening' => false,
    ];

    protected $fillable = [
        'asset_category_id', 'code', 'name', 'acquisition_date', 'acquisition_cost',
        'residual_value', 'useful_life_months', 'depreciation_start_date', 'status',
        'accumulated_depreciation', 'is_opening', 'acquisition_entry_id',
        'disposal_date', 'disposal_amount', 'disposal_entry_id',
    ];

    protected $casts = [
        'acquisition_date' => 'date',
        'depreciation_start_date' => 'date',
        'disposal_date' => 'date',
        'acquisition_cost' => 'integer',
        'residual_value' => 'integer',
        'useful_life_months' => 'integer',
        'accumulated_depreciation' => 'integer',
        'disposal_amount' => 'integer',
        'is_opening' => 'boolean',
        'status' => FixedAssetStatus::class,
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function depreciationRuns(): HasMany
    {
        return $this->hasMany(DepreciationRun::class);
    }

    public function bookValue(): int
    {
        return (int) $this->acquisition_cost - (int) $this->accumulated_depreciation;
    }

    public function depreciableBase(): int
    {
        return (int) $this->acquisition_cost - (int) $this->residual_value;
    }
}
