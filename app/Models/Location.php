<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    protected $fillable = [
        'warehouse_id',
        'parent_location_id',
        'name',
        'type',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'parent_location_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Location::class, 'parent_location_id');
    }

    public function productStocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_stocks')
            ->withPivot('quantity', 'min_stock')
            ->withTimestamps();
    }

    public function fullPath(): string
    {
        $parts = [$this->name];
        $node = $this;
        while ($node->parent_location_id) {
            $node = $node->parent;
            if (!$node) break;
            array_unshift($parts, $node->name);
        }
        array_unshift($parts, $this->warehouse?->name ?? '');

        return implode(' › ', array_filter($parts));
    }

    public static function default(): ?self
    {
        $warehouseId = Warehouse::where('is_default', true)->value('id');
        if (!$warehouseId) {
            return null;
        }
        return static::where('warehouse_id', $warehouseId)
            ->where('is_default', true)
            ->first();
    }
}
