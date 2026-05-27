<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_setting_is_stored_encrypted_in_database(): void
    {
        Setting::set('anthropic_api_key', 'sk-ant-real-key-12345');

        $raw = DB::table('settings')->where('key', 'anthropic_api_key')->value('value');

        $this->assertNotEquals('sk-ant-real-key-12345', $raw, 'API key should NOT be stored as plaintext');
        $this->assertNotEmpty($raw);
        $this->assertStringStartsWith('eyJ', $raw);
    }

    public function test_sensitive_setting_is_returned_decrypted_via_get(): void
    {
        Setting::set('anthropic_api_key', 'sk-ant-real-key-12345');
        Cache::flush();

        $value = Setting::get('anthropic_api_key');

        $this->assertEquals('sk-ant-real-key-12345', $value);
    }

    public function test_non_sensitive_setting_is_stored_plaintext(): void
    {
        Setting::set('store_name', 'Mi Tienda');

        $raw = DB::table('settings')->where('key', 'store_name')->value('value');

        $this->assertEquals('Mi Tienda', $raw, 'Non-sensitive settings should remain plaintext');
    }

    public function test_telegram_token_is_encrypted(): void
    {
        Setting::set('telegram_bot_token', '1234567890:ABCDEFGabcdefg_token');
        Cache::flush();

        $raw = DB::table('settings')->where('key', 'telegram_bot_token')->value('value');
        $this->assertNotEquals('1234567890:ABCDEFGabcdefg_token', $raw);

        $decrypted = Setting::get('telegram_bot_token');
        $this->assertEquals('1234567890:ABCDEFGabcdefg_token', $decrypted);
    }

    public function test_openai_key_is_encrypted(): void
    {
        Setting::set('openai_api_key', 'sk-openai-key-abcdef');
        Cache::flush();

        $raw = DB::table('settings')->where('key', 'openai_api_key')->value('value');
        $this->assertNotEquals('sk-openai-key-abcdef', $raw);

        $this->assertEquals('sk-openai-key-abcdef', Setting::get('openai_api_key'));
    }

    public function test_legacy_plaintext_value_is_readable_after_encryption(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'anthropic_api_key'],
            ['value' => 'sk-legacy-plaintext', 'created_at' => now(), 'updated_at' => now()]
        );
        Cache::flush();

        $value = Setting::get('anthropic_api_key');
        $this->assertEquals('sk-legacy-plaintext', $value);
    }

    public function test_null_sensitive_setting_returns_null(): void
    {
        $value = Setting::get('anthropic_api_key');
        $this->assertNull($value);
    }

    public function test_cached_value_is_decrypted(): void
    {
        Setting::set('anthropic_api_key', 'sk-cached-key');
        $first = Setting::get('anthropic_api_key');
        $second = Setting::get('anthropic_api_key');

        $this->assertEquals('sk-cached-key', $first);
        $this->assertEquals('sk-cached-key', $second);
    }
}
