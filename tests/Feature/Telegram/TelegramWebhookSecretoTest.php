<?php

namespace Tests\Feature\Telegram;

use App\Models\Setting;
use App\Services\Messaging\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * El secreto del webhook se compara en tiempo constante.
 *
 * Con `!==` el tiempo de respuesta delata cuántos caracteres del secreto se
 * acertaron, y con eso se adivina carácter por carácter en vez de a ciegas.
 * `hash_equals` tarda lo mismo acierte o falle.
 *
 * El comportamiento visible no cambia y estos tests lo fijan: sin secreto
 * válido, 401. El caso del secreto vacío va aparte porque `hash_equals('','')`
 * devuelve true, y eso dejaría el webhook abierto justo en la instalación recién
 * hecha, que es cuando nadie lo está mirando.
 */
class TelegramWebhookSecretoTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = '/telegram/webhook';

    protected function setUp(): void
    {
        parent::setUp();

        // El bot saliente no se toca en estas pruebas: solo se mira la puerta.
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->andReturn([]);
        $telegram->shouldReceive('sendChatAction')->andReturn([]);
        $telegram->shouldReceive('answerCallbackQuery')->andReturn([]);
        $this->app->instance(TelegramService::class, $telegram);
    }

    public function test_el_secreto_correcto_sigue_entrando(): void
    {
        Setting::set('telegram_webhook_secret', 'secreto-de-este-negocio');

        $this->postJson(self::RUTA, [], ['X-Telegram-Bot-Api-Secret-Token' => 'secreto-de-este-negocio'])
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_un_secreto_equivocado_sigue_dando_401(): void
    {
        Setting::set('telegram_webhook_secret', 'secreto-de-este-negocio');

        $this->postJson(self::RUTA, [], ['X-Telegram-Bot-Api-Secret-Token' => 'secreto-de-otro-negocio'])
            ->assertStatus(401)
            ->assertJson(['ok' => false]);
    }

    public function test_un_secreto_con_el_prefijo_correcto_tampoco_entra(): void
    {
        // El caso que el ataque de tiempo busca: acertar de a un carácter.
        Setting::set('telegram_webhook_secret', 'secreto-de-este-negocio');

        $this->postJson(self::RUTA, [], ['X-Telegram-Bot-Api-Secret-Token' => 'secreto-de-este-negoci'])
            ->assertStatus(401);
    }

    public function test_sin_cabecera_de_secreto_da_401(): void
    {
        Setting::set('telegram_webhook_secret', 'secreto-de-este-negocio');

        $this->postJson(self::RUTA, [])->assertStatus(401);
    }

    /**
     * Esta prueba mira el código, no la respuesta, y es a propósito.
     *
     * La diferencia entre `!==` y `hash_equals` no se ve en lo que contesta el
     * webhook: las dos cosas devuelven 401. Se ve en cuánto tarda, y medir
     * microsegundos en una suite de tests da falsos positivos todo el tiempo.
     * Así que se fija lo único que se puede fijar sin inventar: que la
     * comparación del secreto sigue siendo la de tiempo constante. Si alguien la
     * vuelve a escribir con `!==`, esto se rompe.
     */
    public function test_la_comparacion_del_secreto_es_de_tiempo_constante(): void
    {
        $fuente = file_get_contents(app_path('Http/Controllers/TelegramWebhookController.php'));

        $this->assertStringContainsString(
            'hash_equals($expectedSecret, $secret)',
            $fuente,
            'El secreto se compara con hash_equals, y el secreto conocido va primero.'
        );
        $this->assertStringNotContainsString(
            '$secret !== $expectedSecret',
            $fuente,
            'La comparación con !== delata, por el tiempo de respuesta, cuántos caracteres se acertaron.'
        );
    }

    public function test_sin_secreto_configurado_la_puerta_queda_cerrada(): void
    {
        // Instalación recién hecha: nadie configuró el secreto todavía.
        // hash_equals('', '') es true, así que este caso se descarta antes.
        $this->postJson(self::RUTA, [], ['X-Telegram-Bot-Api-Secret-Token' => ''])
            ->assertStatus(401);

        $this->postJson(self::RUTA, [])->assertStatus(401);
    }
}
