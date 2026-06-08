<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BillOfMaterial extends Model
{
    use SoftDeletes;

    protected $table = 'bills_of_material';

    protected $fillable = ['product_id', 'mod_rate', 'moi_rate', 'cif_rate', 'is_active'];

    protected $attributes = ['mod_rate' => 0, 'moi_rate' => 0, 'cif_rate' => 0, 'is_active' => true];

    protected $casts = [
        'mod_rate' => 'integer', 'moi_rate' => 'integer', 'cif_rate' => 'integer', 'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(BomComponent::class, 'bom_id');
    }
}
