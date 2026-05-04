<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramConversation extends Model
{
    protected $fillable = ['chat_id', 'step', 'data', 'expires_at'];

    protected $casts = [
        'data' => 'array',
        'expires_at' => 'datetime',
    ];

    public static function getOrCreate(string $chatId): self
    {
        return static::firstOrCreate(
            ['chat_id' => $chatId],
            ['step' => 'idle', 'data' => []]
        );
    }

    public static function cleanExpired(): void
    {
        static::where('expires_at', '<', now())->delete();
    }
}
