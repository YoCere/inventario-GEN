<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    protected $fillable = [
        'name',
        'address',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function rootLocations(): HasMany
    {
        return $this->hasMany(Location::class)->whereNull('parent_location_id');
    }

    public static function default(): ?self
    {
        return static::where('is_default', true)->first();
    }
}
