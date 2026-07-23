<?php

namespace App\Models;

use App\Shop\Models\ProductImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'products';

    protected $fillable = [
        'category_id',
        'unit_id',
        'sku',
        'sin_code',
        'name',
        'slug',
        'purchase_price',
        'selling_price',
        'quantity',
        'min_stock',
        'is_active',
        'is_public',
        'featured',
        'sort_order',
        'description',
        'notes',
        'image_path',
    ];

    protected $casts = [
        'purchase_price' => 'integer',
        'selling_price' => 'integer',
        'quantity' => 'integer',
        'min_stock' => 'integer',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'featured' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function purchaseItems()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function stocks()
    {
        return $this->hasMany(ProductStock::class);
    }

    public function locations()
    {
        return $this->belongsToMany(Location::class, 'product_stocks')
            ->withPivot('quantity', 'min_stock')
            ->withTimestamps();
    }

    public function totalStock(): int
    {
        return (int) $this->stocks()->sum('quantity');
    }

    public function getImageUrlAttribute(): string
    {
        // Prefiere la galería nueva (ProductImage, variantes WebP). El image_path
        // legacy (.jpg) queda como fallback para productos aún no migrados. Sin
        // esto, las vistas internas piden un .jpg viejo/inexistente y dan 404
        // mientras la tienda (que usa ProductImage) sí muestra la imagen.
        $img = $this->primaryImage;
        if ($img) {
            return \Illuminate\Support\Facades\Storage::url($img->path_full ?: $img->path_card ?: $img->path);
        }

        return $this->image_path
            ? \Illuminate\Support\Facades\Storage::url($this->image_path)
            : asset('images/placeholder-product.svg');
    }

    /**
     * True si el producto tiene alguna imagen mostrable (galería nueva o legacy).
     * Las vistas usan esto para decidir si renderizar el bloque de imagen.
     */
    public function hasDisplayImage(): bool
    {
        return $this->primaryImage()->exists() || ! empty($this->image_path);
    }

    /**
     * Galería del producto. Ordenada por sort_order para que el frontend
     * respete el orden definido por el admin.
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    /**
     * Imagen marcada como principal. Usada para tarjetas de catálogo y OG tags.
     */
    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    /**
     * URL del tamaño "card" (~600px) para grids del catálogo público.
     * Cae al image_path legacy si todavía no se generó la galería.
     */
    public function getCardImageUrlAttribute(): string
    {
        $img = $this->primaryImage;
        if ($img) {
            return \Illuminate\Support\Facades\Storage::url($img->path_card ?: $img->path);
        }

        return $this->image_path
            ? \Illuminate\Support\Facades\Storage::url($this->image_path)
            : asset('images/placeholder-product.svg');
    }

    /**
     * Scope para listados públicos del catálogo. Filtra:
     *   - is_public = true (visibilidad pública explícita, separada de is_active interno)
     *   - quantity > 0 si shop_show_out_of_stock != '1' (configurable en Settings)
     */
    public function scopePublic(Builder $query): Builder
    {
        $query->where('is_public', true);

        if (\App\Models\Setting::get('shop_show_out_of_stock') !== '1') {
            $query->where('quantity', '>', 0);
        }

        return $query;
    }
}
