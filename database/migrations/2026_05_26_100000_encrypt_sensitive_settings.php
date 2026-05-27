<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

return new class extends Migration
{
    private const SENSITIVE_KEYS = [
        'anthropic_api_key',
        'openai_api_key',
        'telegram_bot_token',
        'telegram_webhook_secret',
    ];

    public function up(): void
    {
        foreach (self::SENSITIVE_KEYS as $key) {
            $setting = DB::table('settings')->where('key', $key)->first();

            if (! $setting || $setting->value === null) {
                continue;
            }

            try {
                Crypt::decryptString($setting->value);
                continue; // Ya está cifrado
            } catch (DecryptException) {
                // No está cifrado — cifrarlo ahora
            }

            DB::table('settings')
                ->where('key', $key)
                ->update([
                    'value' => Crypt::encryptString($setting->value),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        foreach (self::SENSITIVE_KEYS as $key) {
            $setting = DB::table('settings')->where('key', $key)->first();

            if (! $setting || $setting->value === null) {
                continue;
            }

            try {
                $plain = Crypt::decryptString($setting->value);
                DB::table('settings')
                    ->where('key', $key)
                    ->update(['value' => $plain, 'updated_at' => now()]);
            } catch (DecryptException) {
                // Ya es plaintext
            }
        }
    }
};
