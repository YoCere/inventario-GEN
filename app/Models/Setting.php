<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /**
     * Keys cuyo valor se almacena cifrado en DB.
     * Cache guarda siempre el valor ya descifrado.
     */
    private const ENCRYPTED_KEYS = [
        'anthropic_api_key',
        'openai_api_key',
        'telegram_bot_token',
        'telegram_webhook_secret',
    ];

    /**
     * Get a setting value by key.
     * Sensitive keys are decrypted transparently.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        return Cache::rememberForever("settings.{$key}", function () use ($key, $default) {
            $setting = self::find($key);
            if (! $setting) {
                return $default;
            }

            $value = $setting->value;

            if (in_array($key, self::ENCRYPTED_KEYS, true) && $value !== null) {
                $value = self::safeDecrypt($value);
            }

            return $value ?? $default;
        });
    }

    /**
     * Set a setting value by key.
     * Sensitive keys are encrypted before saving.
     */
    public static function set(string $key, ?string $value): void
    {
        $toStore = $value;

        if ($value !== null && in_array($key, self::ENCRYPTED_KEYS, true)) {
            $toStore = Crypt::encryptString($value);
        }

        self::updateOrCreate(
            ['key' => $key],
            ['value' => $toStore]
        );

        Cache::forget("settings.{$key}");
    }

    /**
     * Intenta descifrar. Si el valor no está cifrado (legacy plaintext),
     * retorna el valor original en lugar de lanzar excepción.
     */
    private static function safeDecrypt(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }
}
