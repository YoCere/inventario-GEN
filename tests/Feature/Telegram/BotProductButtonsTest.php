<?php

namespace Tests\Feature\Telegram;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\TelegramConversation;
use App\Models\TelegramUser;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Agent\AgentContext;
use App\Services\Agent\AgentService;
use App\Services\Agent\ToolRegistry;
use App\Services\Agent\Tools\StartProductCreationTool;
use App\Services\Messaging\TelegramService;
use App\Services\Telegram\BotAgentHandler;
use App\Services\Telegram\BotHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Alta de producto dictada por audio: antes de guardar, la persona ve todo lo
 * que el bot entendió y puede corregir cualquier dato con botones.
 *
 * El caso real: la dueña dicta "mate de cuero, 350 la compra, 450 la venta,
 * categoría mate, 30 unidades" y la transcripción escribe mal el nombre.
 */
class BotProductButtonsTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '555';
    private const OTRO_CHAT = '777';

    /** Mensajes que el bot mandó: texto y botones de cada uno. */
    private array $enviados = [];

    /** Textos con los que el bot contestó cada toque de botón. */
    private array $avisosDeBoton = [];

    protected function setUp(): void
    {
        parent::setUp();

        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->andReturnUsing(
            function (string $chatId, string $text, string $parseMode = 'HTML', ?array $keyboard = null) {
                $this->enviados[] = ['chat_id' => $chatId, 'text' => $text, 'keyboard' => $keyboard];
                return [];
            }
        );
        $telegram->shouldReceive('answerCallbackQuery')->andReturnUsing(
            function (string $touchId, string $text = '', bool $showAlert = false) {
                $this->avisosDeBoton[] = $text;
                return [];
            }
        );
        $telegram->shouldReceive('editMessageReplyMarkup')->andReturn([]);
        $telegram->shouldReceive('sendChatAction')->andReturn([]);
        $this->app->instance(TelegramService::class, $telegram);

        // El alta por bot usa la primera unidad y necesita ubicación por defecto.
        Unit::factory()->create(['name' => 'Unidad', 'symbol' => 'u']);
        Category::factory()->create(['name' => 'Mates', 'slug' => 'mates']);
        $almacen = Warehouse::create(['name' => 'Almacén', 'is_default' => true]);
        Location::create(['name' => 'Estante', 'warehouse_id' => $almacen->id, 'is_default' => true]);

        $duenia = User::factory()->create();
        TelegramUser::create([
            'chat_id'    => self::CHAT,
            'user_id'    => $duenia->id,
            'identifier' => $duenia->email,
            'last_login' => now(),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function texto(string $text, string $chatId = self::CHAT): void
    {
        app(BotHandler::class)->dispatch([
            'message' => ['from' => ['id' => (int) $chatId], 'text' => $text],
        ]);
    }

    /** Simula el toque de un botón tal como Telegram lo manda. */
    private function toqueDeBoton(string $data, string $quienToca = self::CHAT, ?string $chatDelBoton = null): void
    {
        app(BotHandler::class)->dispatch([
            'callback_query' => [
                'id'      => 'toque-1',
                'from'    => ['id' => (int) $quienToca],
                'message' => [
                    'message_id' => 4242,
                    'chat'       => ['id' => (int) ($chatDelBoton ?? $quienToca)],
                ],
                'data'    => $data,
            ],
        ]);
    }

    /**
     * Reproduce el camino del audio: el agente transcribe y llama a la
     * herramienta que pre-llena los datos. El AgentService se reemplaza para no
     * depender del modelo, pero el contexto (y con él el origen 'audio') lo
     * arma el handler real.
     */
    private function audioDeAlta(array $campos): void
    {
        $this->app->bind(AgentService::class, function ($app, $params) use ($campos) {
            return new class($params['tools'], $campos) extends AgentService {
                public function __construct(public ToolRegistry $reg, public array $campos) {}

                public function run(string $userMessage, array $history, AgentContext $context): array
                {
                    app(StartProductCreationTool::class)->execute($this->campos, $context);
                    return ['text' => '', 'messages' => []];
                }
            };
        });

        app(BotAgentHandler::class)->handleVoice(
            self::CHAT,
            'crear producto mate de cuero, 350 la compra, 450 la venta, categoría mate, 30 unidades'
        );
    }

    /** Deja el alta dictada parada en el resumen con botones. */
    private function altaDictadaHastaElResumen(string $nombre = 'Mate de cuero'): void
    {
        $this->audioDeAlta([
            'name'           => $nombre,
            'category'       => 'Mates',
            'purchase_price' => 350,
            'selling_price'  => 450,
            'quantity'       => 30,
        ]);

        // El audio trajo todo menos la foto: el flujo la pide y ella la saltea.
        $this->texto('omitir');
    }

    private function ultimoMensaje(): array
    {
        return $this->enviados[count($this->enviados) - 1];
    }

    private function datosDeBotones(?array $keyboard): array
    {
        $datos = [];
        foreach ($keyboard ?? [] as $fila) {
            foreach ($fila as $boton) {
                $datos[] = $boton['callback_data'];
            }
        }
        return $datos;
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function test_tras_audio_llega_resumen_con_botones_y_el_producto_no_existe_todavia(): void
    {
        $this->altaDictadaHastaElResumen();

        $resumen = $this->ultimoMensaje();

        $this->assertStringContainsString('Revisá antes de guardar', $resumen['text']);
        $this->assertStringContainsString('Mate de cuero', $resumen['text']);
        $this->assertStringContainsString('350.00 Bs', $resumen['text']);
        $this->assertStringContainsString('450.00 Bs', $resumen['text']);
        $this->assertStringContainsString('30', $resumen['text']);

        $this->assertSame(
            ['prod:guardar', 'prod:editar'],
            $this->datosDeBotones($resumen['keyboard']),
            'El resumen del alta dictada debe traer los botones Guardar y Editar.'
        );

        $this->assertSame(0, Product::count(), 'Nada se guarda hasta que la persona toque Guardar.');
        $this->assertSame('nuevo:revisar', TelegramConversation::where('chat_id', self::CHAT)->value('step'));
    }

    public function test_editar_nombre_deja_el_resumen_con_el_nombre_corregido(): void
    {
        // La transcripción escribió mal el nombre.
        $this->altaDictadaHastaElResumen('Mate de cuerpo');

        $this->toqueDeBoton('prod:editar');

        $menu = $this->ultimoMensaje();
        $this->assertStringContainsString('¿Qué dato querés corregir?', $menu['text']);
        $this->assertContains('prod:campo:nombre', $this->datosDeBotones($menu['keyboard']));

        $this->toqueDeBoton('prod:campo:nombre');
        $this->assertStringContainsString('nombre', $this->ultimoMensaje()['text']);

        $this->texto('Mate de cuero');

        $resumen = $this->ultimoMensaje();
        $this->assertStringContainsString('Revisá antes de guardar', $resumen['text']);
        $this->assertStringContainsString('Mate de cuero', $resumen['text']);
        $this->assertStringNotContainsString('Mate de cuerpo', $resumen['text']);
        $this->assertSame(['prod:guardar', 'prod:editar'], $this->datosDeBotones($resumen['keyboard']));

        // Corregir no guarda: sigue esperando el Guardar.
        $this->assertSame(0, Product::count());
    }

    public function test_guardar_crea_el_producto_una_sola_vez(): void
    {
        $this->altaDictadaHastaElResumen();

        $this->toqueDeBoton('prod:guardar');

        $this->assertSame(1, Product::count());
        $producto = Product::first();
        $this->assertSame('Mate de cuero', $producto->name);
        $this->assertSame(35000, $producto->purchase_price);
        $this->assertSame(45000, $producto->selling_price);
        $this->assertSame(30, $producto->quantity);

        // Segundo toque sobre el mismo mensaje (doble tap, o botón viejo).
        $this->toqueDeBoton('prod:guardar');

        $this->assertSame(1, Product::count(), 'Tocar Guardar dos veces no debe duplicar el producto.');
        $this->assertStringContainsString('ya no está disponible', $this->ultimoMensaje()['text']);
    }

    public function test_boton_que_apunta_a_la_conversacion_de_otro_chat_es_rechazado(): void
    {
        $this->altaDictadaHastaElResumen();

        // Alguien más toca un botón cuyo mensaje vive en el chat de la dueña.
        $this->toqueDeBoton('prod:guardar', quienToca: self::OTRO_CHAT, chatDelBoton: self::CHAT);

        $this->assertSame(0, Product::count(), 'Nadie puede guardar el alta de otra persona.');
        $this->assertContains('Este botón no es tuyo.', $this->avisosDeBoton);
        $this->assertSame(
            'nuevo:revisar',
            TelegramConversation::where('chat_id', self::CHAT)->value('step'),
            'El alta de la dueña debe quedar intacta.'
        );
    }

    public function test_el_alta_escrita_paso_a_paso_sigue_confirmando_por_texto_sin_botones(): void
    {
        $this->texto('/nuevo');
        $this->texto('Cinturón de cuero');
        $this->texto('Mates');
        $this->texto('120');
        $this->texto('200');
        $this->texto('8');
        $this->texto('omitir');

        $resumen = $this->ultimoMensaje();
        $this->assertStringContainsString('Resumen del producto', $resumen['text']);
        $this->assertStringContainsString('¿Confirmar crear producto?', $resumen['text']);
        $this->assertNull($resumen['keyboard'], 'El alta escrita no cambia: se confirma escribiendo sí.');
        $this->assertSame('nuevo:confirmar', TelegramConversation::where('chat_id', self::CHAT)->value('step'));
        $this->assertSame(0, Product::count());

        $this->texto('sí');

        $this->assertSame(1, Product::count());
        $this->assertSame('Cinturón de cuero', Product::first()->name);
        $this->assertStringContainsString('Producto creado', $this->ultimoMensaje()['text']);
    }
}
