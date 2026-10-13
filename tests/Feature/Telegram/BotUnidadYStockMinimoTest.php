<?php

namespace Tests\Feature\Telegram;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\Setting;
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
 * Cómo se vende el producto y con cuántas unidades avisar para reponer.
 *
 * Los dos datos los pidió la dueña con estas palabras: "que pida stock mínimo,
 * para evitar que adivine el sistema" y "cómo se vende: por caja, por unidad, el
 * producto solo".
 *
 * Lo que había antes y estos tests no dejan volver:
 *   - `unit_id => 1` fijo: TODO producto cargado por voz quedaba con la primera
 *     unidad del catálogo, que es "Pieza". Una caja de velas se vendía por pieza.
 *   - `min_stock = 20% de la cantidad inicial`: el sistema inventaba con cuántas
 *     avisar, que es exactamente lo que se pidió sacar.
 */
class BotUnidadYStockMinimoTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '555';

    /** Mensajes que mandó el bot: texto y botones de cada uno. */
    private array $enviados = [];

    private Unit $pieza;
    private Unit $caja;

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
        $telegram->shouldReceive('answerCallbackQuery')->andReturn([]);
        $telegram->shouldReceive('editMessageReplyMarkup')->andReturn([]);
        $telegram->shouldReceive('sendChatAction')->andReturn([]);
        $this->app->instance(TelegramService::class, $telegram);

        // Dos formas de venta: así se puede probar que el producto NO queda con
        // la primera (que es la que escribía el `unit_id => 1` de antes).
        $this->pieza = Unit::factory()->create(['name' => 'Pieza', 'symbol' => 'pza']);
        $this->caja  = Unit::factory()->create(['name' => 'Caja', 'symbol' => 'cja']);

        Category::factory()->create(['name' => 'Velas', 'slug' => 'velas']);

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

    // ── Métrica 1: nada adivinado ────────────────────────────────────────────

    public function test_un_producto_cargado_por_el_bot_nunca_queda_con_unidad_ni_stock_minimo_adivinados(): void
    {
        $this->texto('/nuevo');
        $this->texto('Vela aromática');
        $this->texto('velas');
        $this->texto('se vende por caja');
        $this->texto('350');
        $this->texto('450');
        $this->texto('30');
        $this->texto('avisame cuando queden 5');
        $this->texto('omitir');

        $resumen = $this->ultimoMensaje()['text'];
        $this->assertStringContainsString('Se vende por: Caja', $resumen);
        $this->assertStringContainsString('Avisar cuando queden: 5', $resumen);

        $this->toqueDeBoton('prod:guardar');

        $producto = Product::firstOrFail();

        $this->assertSame(
            $this->caja->id,
            $producto->unit_id,
            'Se vende por caja porque la persona lo dijo; no por la primera unidad del catálogo.'
        );
        $this->assertNotSame(1, $producto->unit_id, 'El unit_id fijo en 1 no puede volver.');

        $this->assertSame(5, $producto->min_stock, 'El aviso es el que ella contestó.');
        $this->assertNotSame(
            6,
            $producto->min_stock,
            'Seis es el 20% de 30: ese cálculo inventado es justo lo que se sacó.'
        );
    }

    // ── Métrica 2: si el audio ya lo trajo, no se pregunta ───────────────────

    public function test_cuando_el_audio_dice_como_se_vende_el_bot_no_lo_vuelve_a_preguntar(): void
    {
        $this->audioDeAlta(['unit' => 'caja'] + $this->camposDictados());

        $this->assertStringNotContainsString('Cómo se vende', $this->todoLoDicho());
        $this->assertSame('Caja', $this->datos()['unidad_nombre'] ?? null);
    }

    public function test_cuando_el_audio_no_dice_como_se_vende_el_bot_lo_pregunta(): void
    {
        $this->audioDeAlta($this->camposDictados());

        $this->assertSame('nuevo:unidad', $this->paso());

        $pregunta = $this->ultimoMensaje();
        $this->assertStringContainsString('Cómo se vende', $pregunta['text']);
        $this->assertContains(
            'prod:uni:' . $this->caja->id,
            $this->datosDeBotones($pregunta['keyboard']),
            'Las formas de venta que existen se tienen que poder tocar.'
        );
    }

    public function test_elegir_la_forma_de_venta_tocando_un_boton_avanza_el_alta(): void
    {
        $this->audioDeAlta($this->camposDictados());

        $this->toqueDeBoton('prod:uni:' . $this->caja->id);

        $this->assertSame('Caja', $this->datos()['unidad_nombre'] ?? null);
        $this->assertSame('nuevo:stock_minimo', $this->paso(), 'Elegida la unidad, sigue el aviso de reposición.');
    }

    // ── Métrica 3: "avisame cuando queden 5" ─────────────────────────────────

    public function test_avisame_cuando_queden_cinco_se_entiende_como_stock_minimo(): void
    {
        $this->audioDeAlta(['unit' => 'caja'] + $this->camposDictados());

        $this->assertSame('nuevo:stock_minimo', $this->paso());

        $this->texto('avisame cuando queden 5');

        $this->assertSame(5, $this->datos()['stock_minimo'] ?? null);
    }

    public function test_pedir_que_no_le_avisen_queda_en_cero_y_no_en_uno(): void
    {
        $this->audioDeAlta(['unit' => 'caja'] + $this->camposDictados());

        $this->texto('no me avises');

        $this->assertSame(0, $this->datos()['stock_minimo'] ?? null);
    }

    public function test_un_no_se_no_se_toma_como_no_me_avises(): void
    {
        $this->audioDeAlta(['unit' => 'caja'] + $this->camposDictados());

        $this->texto('este, no sé');

        $this->assertArrayNotHasKey(
            'stock_minimo',
            $this->datos(),
            'Dudar no es pedir que no se avise: eso sería adivinar otra vez.'
        );
        $this->assertSame('nuevo:stock_minimo', $this->paso());
    }

    // ── Métrica 4: el dato apagado no se pregunta ────────────────────────────

    public function test_con_el_stock_minimo_apagado_no_se_pregunta_y_usa_el_valor_configurado(): void
    {
        Setting::set('intake_stock_minimo', '0');
        Setting::set('intake_default_stock_minimo', '3');

        $this->audioDeAlta(['unit' => 'caja'] + $this->camposDictados());
        $this->texto('omitir');
        $this->toqueDeBoton('prod:guardar');

        $this->assertStringNotContainsString('avise para reponer', $this->todoLoDicho());
        $this->assertSame(
            3,
            Product::firstOrFail()->min_stock,
            'Apagado, sale del valor por defecto que la dueña ve en Ajustes.'
        );
    }

    public function test_con_la_forma_de_venta_apagada_usa_la_configurada_y_nunca_la_primera(): void
    {
        Setting::set('intake_unidad', '0');
        Setting::set('intake_default_unidad', 'Caja');

        $this->audioDeAlta($this->camposDictados());
        $this->texto('5');
        $this->texto('omitir');
        $this->toqueDeBoton('prod:guardar');

        $this->assertStringNotContainsString('Cómo se vende', $this->todoLoDicho());
        $this->assertSame($this->caja->id, Product::firstOrFail()->unit_id);
    }

    public function test_con_la_categoria_apagada_tampoco_se_pregunta_y_usa_la_configurada(): void
    {
        Setting::set('intake_categoria', '0');
        Setting::set('intake_default_categoria', 'Varios');

        $this->texto('/nuevo');
        $this->texto('Vela aromática');

        $this->assertStringNotContainsString('categoría', mb_strtolower($this->todoLoDicho()));
        $this->assertSame('Varios', $this->datos()['categoria_nombre'] ?? null);
        $this->assertSame('nuevo:unidad', $this->paso());
    }

    public function test_con_una_sola_forma_de_venta_cargada_no_hay_nada_que_preguntar(): void
    {
        // Negocio que vende todo por unidad: preguntarlo sería un trámite.
        $this->caja->delete();

        $this->audioDeAlta($this->camposDictados());

        $this->assertStringNotContainsString('Cómo se vende', $this->todoLoDicho());
        $this->assertSame('nuevo:stock_minimo', $this->paso());
        $this->assertSame($this->pieza->id, $this->datos()['unidad_id'] ?? null);
    }

    // ── Métrica 5: destacado dicho en el audio ───────────────────────────────

    public function test_marcar_como_destacado_en_el_audio_deja_el_producto_destacado(): void
    {
        $this->audioDeAlta(
            ['unit' => 'caja', 'min_stock' => 5] + $this->camposDictados(),
            'crear producto vela aromática, se vende por caja, y marcalo como destacado'
        );

        $this->texto('omitir');

        $this->assertStringContainsString('Destacado en la tienda', $this->ultimoMensaje()['text']);

        $this->toqueDeBoton('prod:guardar');

        $this->assertTrue(
            (bool) Product::firstOrFail()->featured,
            'Si lo dice en el audio, el producto queda destacado.'
        );
    }

    public function test_un_alta_normal_no_queda_destacada(): void
    {
        $this->audioDeAlta(['unit' => 'caja', 'min_stock' => 5] + $this->camposDictados());
        $this->texto('omitir');
        $this->toqueDeBoton('prod:guardar');

        $this->assertFalse((bool) Product::firstOrFail()->featured);
    }

    public function test_se_puede_destacar_desde_el_menu_de_correccion_sin_ia(): void
    {
        $this->audioDeAlta(['unit' => 'caja', 'min_stock' => 5] + $this->camposDictados());
        $this->texto('omitir');

        $this->toqueDeBoton('prod:editar');
        $this->assertContains('prod:destacar', $this->datosDeBotones($this->ultimoMensaje()['keyboard']));

        $this->toqueDeBoton('prod:destacar');
        $this->toqueDeBoton('prod:guardar');

        $this->assertTrue((bool) Product::firstOrFail()->featured);
    }

    // ── Corrección de los datos nuevos ───────────────────────────────────────

    public function test_el_menu_de_correccion_permite_corregir_los_datos_nuevos(): void
    {
        $this->audioDeAlta(['unit' => 'caja', 'min_stock' => 5] + $this->camposDictados());
        $this->texto('omitir');

        $this->toqueDeBoton('prod:editar');
        $botones = $this->datosDeBotones($this->ultimoMensaje()['keyboard']);

        $this->assertContains('prod:campo:unidad', $botones);
        $this->assertContains('prod:campo:stock_minimo', $botones);

        $this->toqueDeBoton('prod:campo:stock_minimo');
        $this->texto('8');

        $this->assertStringContainsString('Avisar cuando queden: 8', $this->ultimoMensaje()['text']);

        $this->toqueDeBoton('prod:campo:unidad');
        $this->toqueDeBoton('prod:uni:' . $this->pieza->id);

        $resumen = $this->ultimoMensaje()['text'];
        $this->assertStringContainsString('Revisá antes de guardar', $resumen);
        $this->assertStringContainsString('Se vende por: Pieza', $resumen);

        $this->toqueDeBoton('prod:guardar');

        $producto = Product::firstOrFail();
        $this->assertSame($this->pieza->id, $producto->unit_id);
        $this->assertSame(8, $producto->min_stock);
    }

    // ── Con la IA apagada (instancia recién instalada) ───────────────────────

    public function test_sin_ia_una_respuesta_que_no_se_entiende_termina_en_botones_y_no_en_un_pozo(): void
    {
        $this->assertNotSame('1', Setting::get('ai_chatbot_enabled'), 'Este test corre con la IA apagada.');

        $this->audioDeAlta($this->camposDictados());

        $this->texto('este, no sé, a ver');
        $this->assertStringContainsString('No te entendí', $this->ultimoMensaje()['text']);

        $this->texto('mmm');
        $segundoFallo = $this->ultimoMensaje();
        $this->assertStringContainsString('Seguimos sin entendernos', $segundoFallo['text']);
        $this->assertContains('prod:uni:' . $this->caja->id, $this->datosDeBotones($segundoFallo['keyboard']));
        $this->assertSame('nuevo:unidad', $this->paso(), 'Sigue esperando cómo se vende.');
    }

    public function test_sin_ia_lo_hablado_se_entiende_igual(): void
    {
        $this->audioDeAlta($this->camposDictados());

        // Lo determinista alcanza: muletillas afuera, plural a singular.
        $this->texto('ponele que se vende en cajas');

        $this->assertSame('Caja', $this->datos()['unidad_nombre'] ?? null);
    }

    // ── Métrica 6: la venta rápida no se toca ────────────────────────────────

    public function test_la_venta_rapida_por_voz_sigue_funcionando(): void
    {
        $this->seed([
            \Database\Seeders\AccountingPeriodSeeder::class,
            \Database\Seeders\ChartOfAccountSeeder::class,
            \Database\Seeders\SettingSeeder::class,
        ]);

        $producto = Product::factory()->create([
            'name'           => 'mouse gamer unico',
            'selling_price'  => 4000,
            'purchase_price' => 1000,
            'quantity'       => 0,
            'is_active'      => true,
        ]);
        ProductStock::create([
            'product_id'  => $producto->id,
            'location_id' => Location::where('is_default', true)->value('id'),
            'quantity'    => 10,
        ]);
        $producto->update(['quantity' => 10]);

        $this->texto('vende 1 mouse gamer unico a 10');

        $this->assertSame(1, Sale::count(), 'La venta instantánea tiene que seguir andando igual.');
        $this->assertSame(9, $producto->fresh()->quantity);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Lo que trae el dictado, menos la forma de venta y el aviso. */
    private function camposDictados(): array
    {
        return [
            'name'           => 'Vela aromática',
            'category'       => 'Velas',
            'purchase_price' => 350,
            'selling_price'  => 450,
            'quantity'       => 30,
        ];
    }

    /**
     * Reproduce el camino del audio: el agente transcribe y llama a la
     * herramienta que pre-llena los datos. Se reemplaza el AgentService para no
     * depender del modelo; el contexto (con el texto dictado) lo arma el handler
     * real, que es lo que permite detectar "destacado" sin IA de por medio.
     */
    private function audioDeAlta(array $campos, string $transcripcion = 'crear producto vela aromática'): void
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

        app(BotAgentHandler::class)->handleVoice(self::CHAT, $transcripcion);
    }

    private function texto(string $texto): void
    {
        app(BotHandler::class)->dispatch([
            'message' => ['from' => ['id' => (int) self::CHAT], 'text' => $texto],
        ]);
    }

    private function toqueDeBoton(string $data): void
    {
        app(BotHandler::class)->dispatch([
            'callback_query' => [
                'id'      => 'toque-1',
                'from'    => ['id' => (int) self::CHAT],
                'message' => ['message_id' => 4242, 'chat' => ['id' => (int) self::CHAT]],
                'data'    => $data,
            ],
        ]);
    }

    private function ultimoMensaje(): array
    {
        return $this->enviados[count($this->enviados) - 1];
    }

    /** Todo lo que el bot dijo en esta conversación, para buscar una pregunta. */
    private function todoLoDicho(): string
    {
        return implode("\n", array_column($this->enviados, 'text'));
    }

    private function datos(): array
    {
        return TelegramConversation::where('chat_id', self::CHAT)->first()?->data ?? [];
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
