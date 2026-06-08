<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionConsumption extends Model
{
    protected $fillable = ['production_order_id', 'component_product_id', 'quantity', 'unit_cost', 'total_cost'];

    protected $casts = ['quantity' => 'decimal:4', 'unit_cost' => 'integer', 'total_cost' => 'integer'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class, 'production_order_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}
