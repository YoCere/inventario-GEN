<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionOrder extends Model
{
    protected $fillable = [
        'code', 'product_id', 'bom_id', 'quantity', 'production_date', 'location_id',
        'material_cost', 'mod_cost', 'moi_cost', 'cif_cost', 'total_cost', 'unit_cost',
        'journal_entry_id', 'status', 'created_by',
    ];

    protected $attributes = ['status' => 'completed'];

    protected $casts = [
        'production_date' => 'date',
        'quantity' => 'integer',
        'material_cost' => 'integer', 'mod_cost' => 'integer', 'moi_cost' => 'integer',
        'cif_cost' => 'integer', 'total_cost' => 'integer', 'unit_cost' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(ProductionConsumption::class);
    }
}
