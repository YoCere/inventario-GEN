<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageLog extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'user_id',
        'chat_id',
        'channel',
        'model',
        'action',
        'tokens_input',
        'tokens_output',
        'tokens_cache_read',
        'tokens_cache_write',
        'audio_seconds',
        'cost_usd',
        'summary',
        'created_at',
    ];

    protected $casts = [
        'cost_usd' => 'decimal:6',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
