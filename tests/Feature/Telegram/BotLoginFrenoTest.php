<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Messaging\TelegramService;
use App\Services\Telegram\BotHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

/**
 * El freno del ingreso por el chat.
 *
 * Tres agujeros que esto cierra, y que estos tests no dejan volver:
 *
 *  1. Se podían probar contraseñas sin límite: el bot contestaba "contraseña
 *     incorrecta" y dejaba seguir, sin contador ni espera.
 *  2. El bot enumeraba usuarios: contestaba distinto cuando el usuario no
 *     existía, y cuando existía devolvía su NOMBRE REAL, todo eso antes de
 *     haber comprobado una sola contraseña.
 *  3. La contraseña quedaba escrita en el chat, o sea en los servidores de
 *     Telegram y en el historial del teléfono.
 *
 * El repositorio es público, así que cualquiera sabe exactamente cómo funciona
 * este flujo.
 */
class BotLoginFrenoTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '880002';

    /** Textos que mandó el bot. */
    private array $enviados = [];

    /** message_id de cada mensaje que el bot pidió borrar. */
    private array $borrados = [];

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->andReturnUsing(
            function (string $chatId, string $text, string $parseMode = 'HTML', ?array $keyboard = null) {
                $this->enviados[] = $text;
                return [];
            }
        );
        $telegram->shouldReceive('deleteMessage')->andReturnUsing(
            function (string $chatId, int $messageId) {
                $this->borrados[] = $messageId;
                return ['ok' => true];
            }
        );
        $telegram->shouldReceive('answerCallbackQuery')->andReturn([]);
        $telegram->shouldReceive('sendChatAction')->andReturn([]);
        $this->app->instance(TelegramService::class, $telegram);

        $this->usuario = User::factory()->create([
            'name' => 'Manuel Quispe Mamani',
            'email' => 'manuel@tienda.test',
            'password' => Hash::make('clave-de-manuel'),
        ]);
    }

    // ── (a) Límite de intentos ───────────────────────────────────────────────

    public function test_al_sexto_intento_con_la_clave_mal_el_chat_queda_frenado(): void
    {
        $this->texto('hola');
        $this->texto('manuel@tienda.test');

        for ($i = 1; $i <= 5; $i++) {
            $this->enviados = [];
            $this->texto('no-es-la-clave');

            $this->assertStringContainsString(
                'incorrectos',
                $this->todoLoDicho(),
                "El intento {$i} se contesta, todavía no se frena."
            );
            $this->assertStringNotContainsString('Demasiados intentos', $this->todoLoDicho());
        }

        $this->enviados = [];
        $this->texto('no-es-la-clave');

        $this->assertStringContainsString(
            'Demasiados intentos',
            $this->todoLoDicho(),
            'Al sexto se frena: nadie legítimo falla cinco veces seguidas.'
        );
        $this->assertStringContainsString(
            'minuto',
            $this->todoLoDicho(),
            'Se le dice cuánto falta, no solo que no puede.'
        );
    }

    public function test_frenado_ni_la_clave_correcta_entra(): void
    {
        $this->texto('hola');
        $this->texto('manuel@tienda.test');

        for ($i = 1; $i <= 5; $i++) {
            $this->texto('no-es-la-clave');
        }

        $this->enviados = [];
        $this->texto('clave-de-manuel');

        $this->assertStringContainsString('Demasiados intentos', $this->todoLoDicho());
        $this->assertNull(
            TelegramUser::find(self::CHAT),
            'Si el freno se pudiera saltear acertando, no sería un freno.'
        );
    }

    public function test_entrar_bien_limpia_el_contador(): void
    {
        $this->texto('hola');
        $this->texto('manuel@tienda.test');

        // Cuatro fallos: uno menos que el tope.
        for ($i = 1; $i <= 4; $i++) {
            $this->texto('no-es-la-clave');
        }

        $this->texto('clave-de-manuel');
        $this->assertNotNull(TelegramUser::find(self::CHAT), 'La clave buena tiene que entrar.');

        // Si el contador no se hubiera limpiado, quedaría un solo intento antes
        // del freno y un dedazo del día siguiente dejaría a la persona afuera.
        app(\App\Services\Telegram\BotAuthHandler::class)->logout(self::CHAT);
        $this->texto('hola');
        $this->texto('manuel@tienda.test');

        for ($i = 1; $i <= 5; $i++) {
            $this->enviados = [];
            $this->texto('no-es-la-clave');
            $this->assertStringNotContainsString(
                'Demasiados intentos',
                $this->todoLoDicho(),
                "Tras entrar bien, el intento {$i} arranca de cero."
            );
        }
    }

    // ── (b) Dejar de enumerar usuarios ───────────────────────────────────────

    public function test_el_mensaje_no_distingue_usuario_inexistente_de_clave_mala(): void
    {
        $this->texto('hola');
        $this->texto('no-existe-nadie@tienda.test');
        $this->enviados = [];
        $this->texto('cualquier-cosa');
        $conUsuarioInventado = $this->todoLoDicho();

        $this->enviados = [];

        // Mismo flujo, con un usuario que sí existe y la clave equivocada.
        app(\App\Services\Telegram\BotAuthHandler::class)->logout(self::CHAT);
        $this->texto('hola');
        $this->texto('manuel@tienda.test');
        $this->enviados = [];
        $this->texto('no-es-la-clave');
        $conClaveMala = $this->todoLoDicho();

        $this->assertSame(
            $conClaveMala,
            $conUsuarioInventado,
            'Si los dos textos difieren, el chat sirve para averiguar qué cuentas existen.'
        );
        $this->assertStringNotContainsString('no encontrado', $conUsuarioInventado);
    }

    public function test_el_bot_no_dice_el_nombre_real_antes_de_la_clave_correcta(): void
    {
        $this->texto('hola');
        $this->texto('manuel@tienda.test');
        $this->texto('no-es-la-clave');

        $this->assertStringNotContainsString(
            'Manuel Quispe Mamani',
            $this->todoLoDicho(),
            'Sin acertar la contraseña, el nombre del empleado no se regala.'
        );
    }

    public function test_el_segundo_ingreso_tampoco_saluda_con_el_nombre(): void
    {
        // Chat ya vinculado: acá el bot solo pide la contraseña, y antes
        // arrancaba con "Bienvenido <nombre real>".
        TelegramUser::create([
            'chat_id' => self::CHAT,
            'user_id' => $this->usuario->id,
            'identifier' => $this->usuario->email,
            'last_login' => now()->subDays(3), // sesión vencida
        ]);

        $this->texto('hola');

        $this->assertStringNotContainsString('Manuel Quispe Mamani', $this->todoLoDicho());
        $this->assertStringContainsString('contraseña', $this->todoLoDicho());
    }

    public function test_con_la_clave_correcta_si_saluda_por_el_nombre(): void
    {
        $this->texto('hola');
        $this->texto('manuel@tienda.test');
        $this->enviados = [];
        $this->texto('clave-de-manuel');

        $this->assertStringContainsString('Manuel Quispe Mamani', $this->todoLoDicho());
        $this->assertNotNull(TelegramUser::find(self::CHAT));
    }

    // ── (c) Borrar el mensaje con la contraseña ──────────────────────────────

    public function test_el_mensaje_con_la_clave_se_borra_del_chat_aunque_sea_la_correcta(): void
    {
        $this->texto('hola', 11);
        $this->texto('manuel@tienda.test', 12);
        $this->texto('clave-de-manuel', 13);

        $this->assertSame(
            [13],
            $this->borrados,
            'Se borra el mensaje de la contraseña, y solo ese: el usuario no es secreto.'
        );
    }

    public function test_el_mensaje_con_la_clave_se_borra_tambien_cuando_falla(): void
    {
        $this->texto('hola', 21);
        $this->texto('manuel@tienda.test', 22);
        $this->texto('clave-mal-tecleada', 23);

        $this->assertSame(
            [23],
            $this->borrados,
            'Una clave mal tecleada suele ser la buena con un dedazo: también hay que borrarla.'
        );
    }

    public function test_si_telegram_no_deja_borrar_el_login_sigue_funcionando(): void
    {
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->andReturnUsing(
            function (string $chatId, string $text, string $parseMode = 'HTML', ?array $keyboard = null) {
                $this->enviados[] = $text;
                return [];
            }
        );
        $telegram->shouldReceive('deleteMessage')->andThrow(new \RuntimeException('Telegram caído'));
        $telegram->shouldReceive('answerCallbackQuery')->andReturn([]);
        $telegram->shouldReceive('sendChatAction')->andReturn([]);
        $this->app->instance(TelegramService::class, $telegram);

        $this->texto('hola', 31);
        $this->texto('manuel@tienda.test', 32);
        $this->texto('clave-de-manuel', 33);

        $this->assertNotNull(
            TelegramUser::find(self::CHAT),
            'Dejar a la persona afuera por un borrado fallido sería peor que el mensaje que queda.'
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function texto(string $texto, int $messageId = 1): void
    {
        app(BotHandler::class)->dispatch([
            'message' => [
                'message_id' => $messageId,
                'from' => ['id' => (int) self::CHAT],
                'text' => $texto,
            ],
        ]);
    }

    private function todoLoDicho(): string
    {
        return implode("\n", $this->enviados);
    }
}
