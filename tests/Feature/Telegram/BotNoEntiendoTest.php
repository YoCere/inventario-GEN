<?php

namespace Tests\Feature\Telegram;

use App\Models\Category;
use App\Models\Location;
use App\Models\Setting;
use App\Models\TelegramConversation;
use App\Models\TelegramUser;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Messaging\TelegramService;
use App\Services\Telegram\BotHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Cuando el bot no entiende, tiene que preguntar DISTINTO.
 *
 * Repetir la misma pregunta es lo que hace que la persona abandone: ya contestó
 * dos veces y el bot le vuelve a mandar el mismo texto, así que supone que está
 * roto. A la segunda, el bot cambia de estrategia: muestra las opciones más
 * parecidas como botones, más "No sé" para poder seguir.
 */
class BotNoEntiendoTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '555';

    /** @var array<int, array{text: string, keyboard: ?array}> */
    private array $enviados = [];

    protected function setUp(): void
    {
        parent::setUp();

        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->andReturnUsing(
            function (string $chatId, string $text, string $parseMode = 'HTML', ?array $keyboard = null) {
                $this->enviados[] = ['text' => $text, 'keyboard' => $keyboard];

                return [];
            }
        );
        $telegram->shouldReceive('sendChatAction')->andReturn([]);
        $telegram->shouldReceive('answerCallbackQuery')->andReturn([]);
        $telegram->shouldReceive('editMessageReplyMarkup')->andReturn([]);
        $this->app->instance(TelegramService::class, $telegram);

        Setting::set('ai_chatbot_enabled', '0');

        Unit::factory()->create(['name' => 'Unidad', 'symbol' => 'u']);
        Category::factory()->create(['name' => 'Mates', 'slug' => 'mates']);
        Category::factory()->create(['name' => 'Bombillas', 'slug' => 'bombillas']);
        Category::factory()->create(['name' => 'Cintos', 'slug' => 'cintos']);

        $almacen = Warehouse::create(['name' => 'Almacén', 'is_default' => true]);
        Location::create(['name' => 'Estante', 'warehouse_id' => $almacen->id, 'is_default' => true]);

        $duenia = User::factory()->create();
        TelegramUser::create([
            'chat_id' => self::CHAT,
            'user_id' => $duenia->id,
            'identifier' => $duenia->email,
            'last_login' => now(),
        ]);
    }

    public function test_al_segundo_intento_fallido_ofrece_botones_en_vez_de_repetir(): void
    {
        $this->hastaLaCategoria();

        $this->mandarTexto('este, a ver');
        $primeraRespuesta = $this->ultimoMensaje();

        $this->mandarTexto('mmm, no sé bien');
        $segundaRespuesta = $this->ultimoMensaje();

        $this->assertNull($primeraRespuesta['keyboard'], 'La primera vez se vuelve a preguntar normal.');
        $this->assertNotNull($segundaRespuesta['keyboard'], 'A la segunda, botones.');
        $this->assertNotSame(
            $primeraRespuesta['text'],
            $segundaRespuesta['text'],
            'No puede mandar dos veces exactamente el mismo texto.'
        );
    }

    public function test_los_botones_traen_las_categorias_que_ya_existen(): void
    {
        $this->hastaLaCategoria();

        $this->mandarTexto('este, a ver');
        $this->mandarTexto('mmm, no sé bien');

        $botones = $this->datosDeBotones($this->ultimoMensaje()['keyboard']);
        $categoria = Category::where('name', 'Mates')->sole();

        $this->assertContains('prod:cat:' . $categoria->id, $botones);
    }

    public function test_elegir_una_categoria_con_el_boton_avanza_el_alta(): void
    {
        $this->hastaLaCategoria();
        $this->mandarTexto('este, a ver');
        $this->mandarTexto('mmm, no sé bien');

        $categoria = Category::where('name', 'Mates')->sole();
        $this->toqueDeBoton('prod:cat:' . $categoria->id);

        $this->assertSame('Mates', $this->datos()['categoria_nombre'] ?? null);
        $this->assertSame('nuevo:precio_compra', $this->paso());
    }

    public function test_no_se_deja_a_la_persona_sin_salida(): void
    {
        $this->hastaLaCategoria();
        $this->mandarTexto('este, a ver');
        $this->mandarTexto('mmm, no sé bien');

        $botones = $this->datosDeBotones($this->ultimoMensaje()['keyboard']);

        $this->assertContains('prod:cancelar', $botones, 'Siempre tiene que poder salir del alta.');
    }

    public function test_cuando_hay_un_parecido_lo_propone_primero(): void
    {
        $this->hastaLaCategoria();

        // "bombilla" no existe exactamente; "Bombillas" sí.
        $this->mandarTexto('bombillita de alpaca');
        $this->mandarTexto('no sé');

        $botones = $this->datosDeBotones($this->ultimoMensaje()['keyboard']);
        $bombillas = Category::where('name', 'Bombillas')->sole();

        $this->assertSame('prod:cat:' . $bombillas->id, $botones[0] ?? null);
    }

    public function test_contestar_bien_despues_de_fallar_borra_la_cuenta_de_intentos(): void
    {
        $this->hastaLaCategoria();

        $this->mandarTexto('este, a ver');
        $this->mandarTexto('mates');

        $this->assertSame('Mates', $this->datos()['categoria_nombre'] ?? null);
        $this->assertArrayNotHasKey('categoria_intentos', $this->datos());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function hastaLaCategoria(): void
    {
        $this->mandarTexto('crear un producto');
        $this->mandarTexto('Mate imperial');

        $this->assertSame('nuevo:categoria', $this->paso());
    }

    private function mandarTexto(string $texto): void
    {
        app(BotHandler::class)->dispatch([
            'message' => ['from' => ['id' => (int) self::CHAT], 'text' => $texto],
        ]);
    }

    private function toqueDeBoton(string $data): void
    {
        app(BotHandler::class)->dispatch([
            'callback_query' => [
                'id' => 'toque-1',
                'from' => ['id' => (int) self::CHAT],
                'message' => ['message_id' => 4242, 'chat' => ['id' => (int) self::CHAT]],
                'data' => $data,
            ],
        ]);
    }

    /** @return array{text: string, keyboard: ?array} */
    private function ultimoMensaje(): array
    {
        return $this->enviados[count($this->enviados) - 1];
    }

    /** @return array<int, string> */
    private function datosDeBotones(?array $keyboard): array
    {
        $datos = [];

        foreach ($keyboard ?? [] as $fila) {
            foreach ($fila as $boton) {
                $datos[] = $boton['callback_data'] ?? '';
            }
        }

        return $datos;
    }

    private function paso(): ?string
    {
        return TelegramConversation::where('chat_id', self::CHAT)->first()?->step;
    }

    /** @return array<string, mixed> */
    private function datos(): array
    {
        return TelegramConversation::where('chat_id', self::CHAT)->first()?->data ?? [];
    }
}
