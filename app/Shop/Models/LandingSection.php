<?php

namespace App\Shop\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LandingSection extends Model
{
    protected $fillable = ['type', 'sort_order', 'is_enabled', 'data'];

    protected $casts = [
        'is_enabled' => 'boolean',
        'data' => 'array',
    ];

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
