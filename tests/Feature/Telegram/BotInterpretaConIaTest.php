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
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Cuando lo determinista no alcanza, que piense antes de contestar.
 *
 * "las cosas para tomar mate" no se parece en letras a ninguna categoría, pero
 * cualquier persona sabe que va en "Mates". Eso es lo que la IA tiene que
 * resolver: entender lo que quiso decir, eligiendo entre lo que YA existe.
 *
 * Las dos reglas que hacen que esto sea sostenible y están testeadas acá:
 *   1. La IA se consulta SOLO si lo determinista falló (cuesta plata y tarda).
 *   2. Si la IA no está, falla o contesta cualquier cosa, el bot sigue
 *      funcionando como antes. Nunca puede quedar peor que sin IA.
 */
class BotInterpretaConIaTest extends TestCase
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
        $this->app->instance(TelegramService::class, $telegram);

        Setting::set('ai_chatbot_enabled', '1');
        Setting::set('ai_provider', 'anthropic');
        Setting::set('anthropic_api_key', 'sk-de-prueba');

        Unit::factory()->create(['name' => 'Unidad', 'symbol' => 'u']);
        Category::factory()->create(['name' => 'Mates', 'slug' => 'mates']);
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

    public function test_entiende_lo_que_quiso_decir_y_elige_una_categoria_que_ya_existe(): void
    {
        $this->respuestaDeLaIa('Mates');
        $this->hastaLaCategoria();

        $this->mandarTexto('las cosas para tomar mate');

        $this->assertSame('Mates', $this->datos()['categoria_nombre'] ?? null);
        $this->assertSame('nuevo:precio_compra', $this->paso());
    }

    public function test_no_ofrece_crear_una_categoria_cuando_la_ia_reconocio_una_existente(): void
    {
        $this->respuestaDeLaIa('Mates');
        $this->hastaLaCategoria();

        $this->mandarTexto('para los que toman mate');

        $textos = implode("\n", array_column($this->enviados, 'text'));
        $this->assertStringNotContainsString('¿Crear la categoría', $textos);
    }

    public function test_no_gasta_una_llamada_a_la_ia_cuando_ya_se_entendio_sin_ella(): void
    {
        $this->respuestaDeLaIa('Cintos');
        $this->hastaLaCategoria();

        $this->mandarTexto('ponele mates');

        $this->assertSame('Mates', $this->datos()['categoria_nombre'] ?? null);
        Http::assertNothingSent();
    }

    public function test_si_la_ia_inventa_una_categoria_que_no_existe_se_la_ignora(): void
    {
        // Riesgo real de un modelo: contestar algo que no estaba en la lista.
        $this->respuestaDeLaIa('Yerbas y accesorios');
        $this->hastaLaCategoria();

        $this->mandarTexto('algo que no se parece a nada');

        $this->assertNull($this->datos()['categoria_id'] ?? null);
        $this->assertSame('nuevo:categoria', $this->paso(), 'Sigue preguntando la categoría.');
        $this->assertSame(2, Category::count(), 'La IA no puede crear categorías por su cuenta.');
    }

    public function test_si_la_ia_falla_el_alta_sigue_como_siempre(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response('se cayó', 500)]);
        $this->hastaLaCategoria();

        $this->mandarTexto('algo que no se parece a nada');

        $ultimo = $this->enviados[count($this->enviados) - 1]['text'];
        $this->assertStringContainsString('Crear la categoría', $ultimo);
        $this->assertSame('nuevo:categoria', $this->paso());
    }

    public function test_con_la_ia_apagada_todo_funciona_igual_que_antes(): void
    {
        Setting::set('ai_chatbot_enabled', '0');
        Http::fake();

        $this->hastaLaCategoria();
        $this->mandarTexto('algo que no se parece a nada');

        Http::assertNothingSent();
        $this->assertStringContainsString(
            'Crear la categoría',
            $this->enviados[count($this->enviados) - 1]['text']
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function respuestaDeLaIa(string $texto): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => $texto]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
            ], 200),
        ]);
    }

    private function hastaLaCategoria(): void
    {
        $this->mandarTexto('/nuevo');
        $this->mandarTexto('Mate imperial');

        $this->assertSame('nuevo:categoria', $this->paso());
    }

    private function mandarTexto(string $texto): void
    {
        app(BotHandler::class)->dispatch([
            'message' => ['from' => ['id' => (int) self::CHAT], 'text' => $texto],
        ]);
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
