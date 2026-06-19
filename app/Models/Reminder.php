<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Reminder extends Model
{
    /** @use HasFactory<\Database\Factories\ReminderFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'chat_id', 'title', 'body', 'remind_at', 'timezone',
        'recurrence', 'recurrence_rule', 'remindable_type', 'remindable_id',
        'status', 'last_sent_at', 'sent_count', 'created_via',
    ];

    protected $casts = [
        'remind_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'recurrence_rule' => 'array',
        'sent_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function remindable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Aislamiento estricto: solo recordatorios del usuario dado. */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function isRecurring(): bool
    {
        return $this->recurrence !== 'none';
    }
}
