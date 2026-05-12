<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramUser extends Model
{
    protected $primaryKey = 'chat_id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['chat_id', 'user_id', 'identifier', 'last_login'];

    protected $casts = [
        'last_login' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
