<?php

namespace Tests\Feature\Telegram;

use App\Models\Category;
use App\Models\Location;
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
use App\Services\Telegram\UnidadDeVenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Cuando el audio ya dijo cómo se vende, el bot no lo vuelve a preguntar.
 *
 * El reclamo vino de Manuel cargando productos por voz: dictaba "se lo venderá
 * por unidad" y el bot le preguntaba igual "¿Cómo se vende este producto?",
 * con la lista de doce opciones. Tenía que contestar dos veces lo mismo.
 *
 * Había dos fallas encadenadas:
 *
 *   1. `buscar('unidad')` devolvía null aunque la unidad "Unidad" exista en la
 *      base. "unidad" está en las muletillas del paso (para que "la unidad es
 *      caja" llegue a "caja"), así que la limpieza se comía la respuesta
 *      entera y no quedaba nada que buscar. Era la respuesta más común del
 *      catálogo y era justo la que no se entendía: en el chat de Manuel solo
 *      resolvió porque cayó al fallback de IA, que cuesta una llamada.
 *
 *   2. Que la unidad se detectara dependía de que el modelo se acordara de
 *      pasar el campo `unit`. Igual que con "destacado", la forma de venta se
 *      busca ahora directo en lo que la persona dictó.
 */
class BotUnidadDichaEnAudioTest extends TestCase
{
    use RefreshDatabase;

    private function catalogo(): void
    {
        // El orden importa: "Pieza" es la primera del catálogo que trae el
        // sistema, así que si algo resuelve mal va a resolver a Pieza.
        Unit::factory()->create(['name' => 'Pieza', 'symbol' => 'pza']);
        Unit::factory()->create(['name' => 'Unidad', 'symbol' => 'un']);
        Unit::factory()->create(['name' => 'Caja', 'symbol' => 'cja']);
        Unit::factory()->create(['name' => 'Metro', 'symbol' => 'm']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function formasDeDecirlo(): array
    {
        return [
            'la palabra sola'            => ['unidad', 'Unidad'],
            'en plural'                  => ['unidades', 'Unidad'],
            'con preposición'            => ['por unidad', 'Unidad'],
            'frase entera'               => ['se vende por unidad', 'Unidad'],
            'como lo dijo Manuel'        => ['se lo venderá por unidad', 'Unidad'],
            'el producto solo'           => ['el producto solo', 'Unidad'],
            'otra unidad, que ya andaba' => ['por caja', 'Caja'],
            'la unidad es otra cosa'     => ['la unidad es caja', 'Caja'],
        ];
    }

    /** @dataProvider formasDeDecirlo */
    public function test_se_entiende_sin_gastar_una_llamada_a_la_ia(string $dicho, string $esperada): void
    {
        $this->catalogo();

        $unidad = app(UnidadDeVenta::class)->buscar($dicho);

        $this->assertNotNull($unidad, "No se entendió \"{$dicho}\".");
        $this->assertSame($esperada, $unidad->name, "\"{$dicho}\" se entendió como {$unidad->name}.");
    }

    /** Pura muletilla sigue siendo "no entendí": inventar una unidad es peor. */
    public function test_lo_que_no_dice_nada_sigue_sin_resolver(): void
    {
        $this->catalogo();

        $this->assertNull(app(UnidadDeVenta::class)->buscar('eh, mmm, no sé'));
        $this->assertNull(app(UnidadDeVenta::class)->buscar(''));
    }

    // ── Lo dicho en el audio, sin depender del modelo ─────────────────────────

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function audios(): array
    {
        return [
            'lo que dictó Manuel' => [
                'quiero registrar una faja para adulto de 12 centímetros de ancho, '
                . 'de 2 metros y medio de largo, categoría ropa de campo, 80 de compra, '
                . '140 de venta, 4 de stock, avisame cuando queden 2, se lo venderá por unidad',
                'Unidad',
            ],
            'se vende por caja' => [
                'nuevo producto velas aromáticas, se vende por caja, 20 la compra y 35 la venta',
                'Caja',
            ],
            'viene en cajas' => [
                'cargá gaseosas, vienen en cajas de 12, 60 de compra',
                'Caja',
            ],
            'no dijo cómo se vende' => [
                'registrar faja de 2 metros y medio de largo, 80 de compra, 140 de venta',
                null,
            ],
            'habla de metros pero no de cómo se vende' => [
                'producto cable de 3 metros, 10 de compra, 25 de venta, categoría electrónica',
                null,
            ],
        ];
    }

    /**
     * El caso que NO tiene que resolver es tan importante como los otros: el
     * nombre del producto de Manuel dice "2 metros y medio de largo". Buscar
     * cualquier palabra de unidad suelta en el audio dejaría esa faja
     * vendiéndose por metro, y nadie se enteraría hasta contar el stock.
     *
     * @dataProvider audios
     */
    public function test_la_forma_de_venta_se_saca_del_audio_solo_si_se_dijo(string $audio, ?string $esperada): void
    {
        $this->catalogo();

        $unidad = app(UnidadDeVenta::class)->enLoDicho($audio);

        if ($esperada === null) {
            $this->assertNull(
                $unidad,
                'El audio no dice cómo se vende, pero se entendió ' . ($unidad->name ?? '') . '.'
            );

            return;
        }

        $this->assertNotNull($unidad, 'No se sacó la forma de venta del audio.');
        $this->assertSame($esperada, $unidad->name);
    }

    public function test_sin_mensaje_no_se_inventa_nada(): void
    {
        $this->catalogo();

        $this->assertNull(app(UnidadDeVenta::class)->enLoDicho(null));
        $this->assertNull(app(UnidadDeVenta::class)->enLoDicho(''));
    }

    // ── El camino completo: dictar y que no vuelva a preguntar ───────────────

    private const CHAT = '777';

    /** Lo que mandó el bot, en orden. */
    private array $enviados = [];

    /** Lo que dictó Manuel, palabra por palabra. */
    private const DICTADO = 'quiero registrar una faja para adulto de 12 centímetros de ancho, '
        . 'de 2 metros y medio de largo, categoría ropa de campo, 80 de compra, '
        . '140 de venta, 4 de stock, avisame cuando queden 2, se la venderá por unidad';

    private function escenario(): void
    {
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->andReturnUsing(
            function (string $chatId, string $text, string $parseMode = 'HTML', ?array $keyboard = null) {
                $this->enviados[] = $text;

                return [];
            }
        );
        $telegram->shouldReceive('answerCallbackQuery')->andReturn([]);
        $telegram->shouldReceive('editMessageReplyMarkup')->andReturn([]);
        $telegram->shouldReceive('sendChatAction')->andReturn([]);
        $this->app->instance(TelegramService::class, $telegram);

        $this->catalogo();

        Category::factory()->create(['name' => 'Ropa de campo', 'slug' => 'ropa-de-campo']);

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

    private function campos(bool $modeloPasaUnidad): array
    {
        return array_filter([
            'name'           => 'Faja para adulto de 12 centímetros de ancho',
            'category'       => 'Ropa de campo',
            'purchase_price' => 80,
            'selling_price'  => 140,
            'quantity'       => 4,
            'min_stock'      => 2,
            'unit'           => $modeloPasaUnidad ? 'unidad' : null,
        ]);
    }

    public function test_si_el_modelo_pasa_la_unidad_el_bot_no_la_vuelve_a_preguntar(): void
    {
        $this->escenario();

        $this->audioDeAlta($this->campos(true), self::DICTADO);

        $this->assertNoPreguntoComoSeVende();
        $this->assertStringContainsString('Se vende por: <b>Unidad</b>', $this->todoLoEnviado());
    }

    /**
     * El mismo audio, pero el modelo se olvidó de pasar `unit`. Es el caso que
     * de verdad le pasó a Manuel, y el que no se arregla pidiéndole al modelo
     * que se acuerde mejor.
     */
    public function test_aunque_el_modelo_no_pase_la_unidad_se_saca_de_lo_dictado(): void
    {
        $this->escenario();

        $this->audioDeAlta($this->campos(false), self::DICTADO);

        $this->assertNoPreguntoComoSeVende();
        $this->assertStringContainsString('Se vende por: <b>Unidad</b>', $this->todoLoEnviado());
    }

    /**
     * Lo de siempre, que no se puede romper: si nadie dijo cómo se vende, hay
     * que preguntar. Adivinar "Unidad" porque es lo más común es justo lo que la
     * dueña pidió sacar ("que no adivine el sistema").
     */
    public function test_si_nadie_dijo_como_se_vende_sigue_preguntando(): void
    {
        $this->escenario();

        $this->audioDeAlta(
            $this->campos(false),
            'registrar faja para adulto, categoría ropa de campo, 80 de compra, 140 de venta, 4 de stock'
        );

        $this->assertTrue(
            $this->preguntoComoSeVende(),
            'Sin que nadie lo diga, la forma de venta hay que preguntarla.'
        );
    }

    private function todoLoEnviado(): string
    {
        return implode("\n---\n", $this->enviados);
    }

    private function preguntoComoSeVende(): bool
    {
        foreach ($this->enviados as $texto) {
            if (str_contains($texto, 'Cómo se vende')) {
                return true;
            }
        }

        return false;
    }

    private function assertNoPreguntoComoSeVende(): void
    {
        $this->assertFalse(
            $this->preguntoComoSeVende(),
            "El audio ya dijo cómo se vende y el bot lo preguntó igual:\n" . $this->todoLoEnviado()
        );
    }

    /**
     * El camino del audio tal cual corre: el agente transcribe y llama a la
     * herramienta. Se reemplaza el AgentService para no depender del modelo,
     * pero el contexto (con el texto dictado) lo arma el handler real — que es
     * justo lo que permite sacar la forma de venta sin IA de por medio.
     */
    private function audioDeAlta(array $campos, string $transcripcion): void
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
}
