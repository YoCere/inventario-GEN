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
use App\Services\Agent\WhisperService;
use App\Services\Messaging\TelegramService;
use App\Services\Telegram\BotHandler;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * La edición, entrando por donde entra una persona de verdad.
 *
 * El flujo de edición y el ruteo del bot los construyeron dos agentes en
 * paralelo, cada uno con sus tests: uno llamaba al flujo de edición directo, el
 * otro emitía el botón sin que nadie lo atendiera. El cable entre los dos —
 * botones, texto, voz y fotos — no estaba probado por nadie, y es justo donde
 * se rompen las cosas cuando se juntan dos trabajos.
 *
 * Esto cubre el pedido textual del dueño: "sale el botón de editar pero al
 * presionarlo desde el bot sale que no se puede modificar un producto antiguo".
 */
class BotEdicionIntegradaTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '555';

    /** @var array<int, array{text: string, keyboard: ?array}> */
    private array $enviados = [];

    private Product $producto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->andReturnUsing(
            function (string $chatId, string $text, string $parseMode = 'HTML', ?array $keyboard = null) {
                $this->enviados[] = ['text' => $text, 'keyboard' => $keyboard];

                return [];
            }
        );
        $telegram->shouldReceive('sendPhoto')->andReturn([]);
        $telegram->shouldReceive('getFile')->andReturn('ruta/audio.ogg');
        $telegram->shouldReceive('downloadFile')->andReturn('bytes-de-audio');
        $telegram->shouldReceive('sendChatAction')->andReturn([]);
        $telegram->shouldReceive('answerCallbackQuery')->andReturn([]);
        $telegram->shouldReceive('editMessageReplyMarkup')->andReturn([]);
        $this->app->instance(TelegramService::class, $telegram);

        Setting::set('ai_chatbot_enabled', '0');

        $unidad = Unit::factory()->create(['name' => 'Pieza', 'symbol' => 'pza']);
        $categoria = Category::factory()->create(['name' => 'Mates', 'slug' => 'mates']);
        Category::factory()->create(['name' => 'Sombreros', 'slug' => 'sombreros']);

        $almacen = Warehouse::create(['name' => 'Almacén', 'is_default' => true]);
        Location::create(['name' => 'Estante', 'warehouse_id' => $almacen->id, 'is_default' => true]);

        // Un producto cargado hace meses: el caso que fallaba.
        $this->producto = Product::factory()->create([
            'name' => 'mate camionero con madera de algarrobo',
            'sku' => 'P.250101.AAAA',
            'category_id' => $categoria->id,
            'unit_id' => $unidad->id,
            'selling_price' => 26000,
            'is_active' => true,
            'created_at' => now()->subMonths(8),
        ]);

        $duenia = User::factory()->create();
        $duenia->assignRole('emprendedor');
        TelegramUser::create([
            'chat_id' => self::CHAT,
            'user_id' => $duenia->id,
            'identifier' => $duenia->email,
            'last_login' => now(),
        ]);
    }

    public function test_el_boton_editar_de_un_producto_viejo_abre_la_edicion(): void
    {
        $this->toqueDeBoton('edit:abrir:' . $this->producto->id);

        $this->assertStringStartsWith(
            'editar:',
            $this->paso() ?? '',
            'El botón Editar tiene que abrir la edición, no decir que el registro no está disponible.'
        );
        $this->assertStringNotContainsString(
            'ya no está disponible',
            $this->ultimoTexto(),
        );
    }

    public function test_pedirlo_hablando_llega_al_flujo_de_edicion(): void
    {
        $this->mandarTexto('quiero editar un producto');

        $this->assertStringStartsWith('editar:', $this->paso() ?? '');
    }

    public function test_cambiar_el_precio_de_punta_a_punta_sin_tocar_nada_mas(): void
    {
        $this->mandarTexto('cambiale el precio de venta del mate camionero a 300');

        // Nada se guarda hasta confirmar.
        $this->assertSame(26000, $this->producto->fresh()->selling_price);

        $this->toqueDeBoton('edit:guardar');

        $this->assertSame(30000, $this->producto->fresh()->selling_price, 'El precio tenía que quedar en 300.');
        $this->assertSame('mate camionero con madera de algarrobo', $this->producto->fresh()->name, 'No se toca nada más.');
    }

    public function test_contestar_hablando_dentro_de_la_edicion_tambien_funciona(): void
    {
        Setting::set('ai_voice_enabled', '1');

        $this->mandarTexto('quiero editar el mate camionero');

        $whisper = Mockery::mock(WhisperService::class);
        $whisper->shouldReceive('transcribe')->andReturn('la categoría sombreros');
        $this->app->instance(WhisperService::class, $whisper);

        // Abrir el dato categoría y contestarlo por audio.
        $this->toqueDeBoton('edit:campo:categoria');
        $this->mandarAudio();

        $this->toqueDeBoton('edit:guardar');

        $this->assertSame(
            'Sombreros',
            $this->producto->fresh()->category->name,
            'La respuesta hablada dentro de la edición tiene que llegar al flujo de edición.'
        );
    }

    public function test_cancelar_saca_de_la_edicion_sin_guardar(): void
    {
        $this->mandarTexto('cambiale el precio de venta del mate camionero a 300');
        $this->mandarTexto('/cancelar');

        $this->assertSame(26000, $this->producto->fresh()->selling_price);

        $paso = $this->paso();
        $this->assertTrue(
            $paso === null || ! str_starts_with($paso, 'editar:'),
            'Después de /cancelar no puede quedar una edición abierta.'
        );
    }

    public function test_quien_no_tiene_permiso_no_puede_editar_ni_tocando_el_boton(): void
    {
        $vendedor = User::factory()->create();
        $vendedor->assignRole('staff');
        TelegramUser::where('chat_id', self::CHAT)->update(['user_id' => $vendedor->id]);

        $this->toqueDeBoton('edit:abrir:' . $this->producto->id);
        $this->mandarTexto('cambiale el precio de venta del mate camionero a 300');

        $this->assertSame(26000, $this->producto->fresh()->selling_price);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function mandarTexto(string $texto): void
    {
        app(BotHandler::class)->dispatch([
            'message' => ['from' => ['id' => (int) self::CHAT], 'text' => $texto],
        ]);
    }

    private function mandarAudio(): void
    {
        app(BotHandler::class)->dispatch([
            'message' => [
                'from' => ['id' => (int) self::CHAT],
                'voice' => ['file_id' => 'abc', 'duration' => 3],
            ],
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

    private function ultimoTexto(): string
    {
        return $this->enviados === [] ? '' : $this->enviados[count($this->enviados) - 1]['text'];
    }
}
