<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cifrar settings.value no sirve de nada si el valor descifrado termina guardado
 * para siempre en la tabla `cache` (CACHE_STORE=database en produccion): quien
 * lea la base —o el volcado que viaja en el zip del respaldo— ve el secreto en
 * claro. Estos tests consultan la tabla de verdad y buscan el valor.
 */
class SettingSecretCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // En testing CACHE_STORE=array (memoria), que es justo lo que no reproduce
        // el problema. Forzamos el store `database`, el de produccion.
        config(['cache.default' => 'database']);
    }

    /** Todo lo que haya quedado escrito en el store compartido, como texto. */
    private function volcadoDelCache(): string
    {
        return DB::table('cache')->get()->map(
            fn ($row) => $row->key . '|' . $row->value
        )->implode("\n");
    }

    public function test_secreto_no_queda_en_claro_en_la_tabla_cache(): void
    {
        Setting::set('anthropic_api_key', 'sk-ant-secreto-no-debe-estar-en-cache');

        $this->assertSame('sk-ant-secreto-no-debe-estar-en-cache', Setting::get('anthropic_api_key'));

        $this->assertStringNotContainsString(
            'sk-ant-secreto-no-debe-estar-en-cache',
            $this->volcadoDelCache(),
            'El valor descifrado NO debe escribirse en el store compartido'
        );
    }

    public function test_ningun_secreto_queda_en_la_tabla_cache(): void
    {
        $secretos = [
            'anthropic_api_key' => 'sk-ant-aaa-111',
            'openai_api_key' => 'sk-openai-bbb-222',
            'telegram_bot_token' => '999888:TOKEN-ccc-333',
            'telegram_webhook_secret' => 'whsec-ddd-444',
            'siat_api_token' => 'siat-token-eee-555',
            'siat_credential' => 'siat-cred-fff-666',
        ];

        foreach ($secretos as $clave => $valor) {
            Setting::set($clave, $valor);
            $this->assertSame($valor, Setting::get($clave));
        }

        $volcado = $this->volcadoDelCache();

        foreach ($secretos as $clave => $valor) {
            $this->assertStringNotContainsString($valor, $volcado, "{$clave} quedo en claro en `cache`");
        }
    }

    public function test_ajuste_no_sensible_sigue_cacheado(): void
    {
        Setting::set('store_name', 'Talabarteria Rosita');

        $this->assertSame('Talabarteria Rosita', Setting::get('store_name'));

        $this->assertStringContainsString(
            'Talabarteria Rosita',
            $this->volcadoDelCache(),
            'Los ajustes normales son muchos y se leen todo el tiempo: deben seguir cacheados'
        );
    }

    public function test_leer_dos_veces_devuelve_lo_mismo(): void
    {
        Setting::set('telegram_bot_token', '123:abc');

        $this->assertSame('123:abc', Setting::get('telegram_bot_token'));
        $this->assertSame('123:abc', Setting::get('telegram_bot_token'));
    }

    public function test_set_invalida_el_valor_en_memoria(): void
    {
        Setting::set('openai_api_key', 'sk-vieja');
        $this->assertSame('sk-vieja', Setting::get('openai_api_key'));

        Setting::set('openai_api_key', 'sk-nueva');
        $this->assertSame('sk-nueva', Setting::get('openai_api_key'));
    }

    public function test_forget_permite_recargar_un_secreto_cambiado_por_fuera(): void
    {
        // Lo necesitan los procesos largos (el bot escucha durante horas): el
        // secreto ya no vive en el store compartido, asi que Cache::forget() solo
        // no alcanza para tomar un token cargado despues del arranque.
        Setting::set('telegram_bot_token', '111:viejo');
        $this->assertSame('111:viejo', Setting::get('telegram_bot_token'));

        DB::table('settings')->where('key', 'telegram_bot_token')->update([
            'value' => \Illuminate\Support\Facades\Crypt::encryptString('222:nuevo'),
        ]);

        Setting::forget('telegram_bot_token');

        $this->assertSame('222:nuevo', Setting::get('telegram_bot_token'));
    }

    public function test_valor_heredado_en_texto_plano_sigue_legible(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'siat_api_token'],
            ['value' => 'token-legacy-sin-cifrar', 'created_at' => now(), 'updated_at' => now()]
        );
        Cache::flush();

        $this->assertSame('token-legacy-sin-cifrar', Setting::get('siat_api_token'));
    }

    public function test_la_migracion_borra_los_secretos_que_ya_estaban_en_cache(): void
    {
        // Simula lo que dejo el codigo anterior: el secreto descifrado, guardado
        // para siempre en la tabla `cache` con el prefijo del store.
        DB::table('cache')->insert([
            ['key' => 'laravel_cache_settings.anthropic_api_key', 'value' => serialize('sk-ant-vieja-en-claro'), 'expiration' => 9999999999],
            ['key' => 'laravel_cache_settings.store_name', 'value' => serialize('Mi Tienda'), 'expiration' => 9999999999],
        ]);

        $migracion = require base_path('database/migrations/2026_11_03_000001_purge_plaintext_secrets_from_cache.php');
        $migracion->up();

        $this->assertDatabaseMissing('cache', ['key' => 'laravel_cache_settings.anthropic_api_key']);
        $this->assertDatabaseHas('cache', ['key' => 'laravel_cache_settings.store_name']);
    }
}
