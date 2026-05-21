<?php

namespace App\Shop\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    protected $fillable = [
        'product_id',
        'path',
        'path_thumb',
        'path_card',
        'path_full',
        'alt_text',
        'sort_order',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * URL para galería tamaño completo. Si la variante específica no se ha
     * generado todavía (legacy images pre-Bloque C), cae al path original.
     */
    public function getFullUrlAttribute(): string
    {
        return Storage::url($this->path_full ?: $this->path);
    }

    public function getCardUrlAttribute(): string
    {
        return Storage::url($this->path_card ?: $this->path);
    }

    public function getThumbUrlAttribute(): string
    {
        return Storage::url($this->path_thumb ?: $this->path);
    }
}
