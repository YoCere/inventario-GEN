<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebConversation extends Model
{
    protected $fillable = ['user_id', 'messages'];

    protected $casts = ['messages' => 'array'];

    public static function getOrCreate(User $user): self
    {
        return static::firstOrCreate(['user_id' => $user->id], ['messages' => []]);
    }

    /** @return array<int, array<string, mixed>> */
    public function history(): array
    {
        return is_array($this->messages) ? $this->messages : [];
    }

    /** @param array<int, array<string, mixed>> $messages */
    public function saveHistory(array $messages, int $limit): void
    {
        $this->update(['messages' => array_slice($messages, -$limit)]);
    }

    public function clear(): void
    {
        $this->update(['messages' => []]);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
