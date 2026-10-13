<?php

namespace Tests\Feature\Telegram;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
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
 * El alta escrita paso a paso también se revisa antes de guardar.
 *
 * Hasta ahora el resumen con botones Guardar / Editar aparecía solo cuando el
 * alta venía de un audio, con el argumento de que la transcripción es lo único
 * que se equivoca. En el uso real la dueña también se equivoca escribiendo —y
 * corregir tocando un botón le sale más fácil que volver a empezar.
 */
class BotAltaEscritaTest extends TestCase
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

    public function test_el_alta_escrita_termina_en_el_resumen_con_botones_y_sin_crear_nada(): void
    {
        $this->altaEscritaCompleta();

        $this->assertSame('nuevo:revisar', $this->paso());
        $this->assertSame(0, Product::count(), 'Nada se guarda hasta tocar Guardar.');

        $ultimo = $this->enviados[count($this->enviados) - 1];
        $botones = $this->datosDeBotones($ultimo['keyboard']);

        $this->assertContains('prod:guardar', $botones);
        $this->assertContains('prod:editar', $botones);
    }

    public function test_guardar_crea_el_producto_una_sola_vez(): void
    {
        $this->altaEscritaCompleta();

        $this->toqueDeBoton('prod:guardar');
        $this->toqueDeBoton('prod:guardar');

        $this->assertSame(1, Product::count());
        $this->assertSame('Mate imperial', Product::first()->name);
    }

    public function test_se_puede_corregir_un_dato_escrito_antes_de_guardar(): void
    {
        $this->altaEscritaCompleta();

        $this->toqueDeBoton('prod:editar');
        $this->toqueDeBoton('prod:campo:nombre');
        $this->mandarTexto('Mate imperial de alpaca');
        $this->toqueDeBoton('prod:guardar');

        $this->assertSame('Mate imperial de alpaca', Product::first()->name);
    }

    public function test_escribir_guardar_tambien_sirve_para_quien_no_usa_botones(): void
    {
        $this->altaEscritaCompleta();

        $this->mandarTexto('guardar');

        $this->assertSame(1, Product::count());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Alta completa escrita a mano, sin un solo audio. */
    private function altaEscritaCompleta(): void
    {
        $this->mandarTexto('crear un producto');
        $this->mandarTexto('Mate imperial');
        $this->mandarTexto('mates');
        $this->mandarTexto('250');
        $this->mandarTexto('350');
        $this->mandarTexto('20');
        // Con cuántas avisar para reponer: el bot lo pregunta y ella lo contesta.
        // Antes el sistema lo inventaba como el 20% de la cantidad inicial.
        $this->mandarTexto('5');
        $this->mandarTexto('omitir');
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

    private function paso(): ?string
    {
        return TelegramConversation::where('chat_id', self::CHAT)->first()?->step;
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
}
