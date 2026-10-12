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
use App\Services\Agent\AgentContext;
use App\Services\Agent\AgentService;
use App\Services\Agent\ToolRegistry;
use App\Services\Agent\WhisperService;
use App\Services\Messaging\TelegramService;
use App\Services\Telegram\BotHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * El bot tiene que entender QUÉ quiere hacer la persona antes de ponerse a buscar.
 *
 * El caso real: la dueña escribe "crear un producto" y el bot se pone a buscar un
 * producto llamado así. Como la búsqueda es difusa, a veces hasta encuentra algo
 * parecido y le muestra una ficha que no tiene nada que ver.
 *
 * Importante: esto NO puede depender del asistente de IA. Tiene que funcionar
 * igual con la IA apagada, que es como queda una instancia recién instalada.
 */
class BotIntentTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '555';

    /** @var array<int, string> */
    private array $enviados = [];

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
        $telegram->shouldReceive('sendChatAction')->andReturn([]);
        $telegram->shouldReceive('answerCallbackQuery')->andReturn([]);
        $telegram->shouldReceive('getFile')->andReturn('ruta/audio.ogg');
        $telegram->shouldReceive('downloadFile')->andReturn('bytes-de-audio');
        $this->app->instance(TelegramService::class, $telegram);

        // IA apagada a propósito: el alta por intención no puede depender de ella.
        Setting::set('ai_chatbot_enabled', '0');

        $unidad = Unit::factory()->create(['name' => 'Unidad', 'symbol' => 'u']);
        $categoria = Category::factory()->create(['name' => 'Mates', 'slug' => 'mates']);
        $almacen = Warehouse::create(['name' => 'Almacén', 'is_default' => true]);
        Location::create(['name' => 'Estante', 'warehouse_id' => $almacen->id, 'is_default' => true]);

        // Un producto que existe de verdad, para distinguir búsqueda de intención.
        Product::factory()->create([
            'name' => 'Mate imperial virola de acero',
            'category_id' => $categoria->id,
            'unit_id' => $unidad->id,
            'is_active' => true,
        ]);

        $duenia = User::factory()->create();
        TelegramUser::create([
            'chat_id' => self::CHAT,
            'user_id' => $duenia->id,
            'identifier' => $duenia->email,
            'last_login' => now(),
        ]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('formasDePedirElAlta')]
    public function test_pedir_un_producto_nuevo_abre_el_alta_y_no_una_busqueda(string $frase): void
    {
        $this->mandarTexto($frase);

        $this->assertStringStartsWith(
            'nuevo:',
            $this->pasoActual() ?? '',
            "«{$frase}» tenía que abrir el alta de producto, no una búsqueda."
        );
    }

    /** @return array<string, array{0: string}> */
    public static function formasDePedirElAlta(): array
    {
        $frases = [
            'crear un producto',
            'crear producto',
            'quiero crear un producto',
            'nuevo producto',
            'producto nuevo',
            'registrar un producto',
            'dar de alta un producto',
            'agregar un producto',
            'añadir producto',
            'cargar un producto nuevo',
            'necesito dar de alta un articulo',
            'voy a registrar una mercadería',
        ];

        return array_combine($frases, array_map(fn ($f) => [$f], $frases));
    }

    public function test_un_audio_pidiendo_el_alta_tambien_la_abre(): void
    {
        Setting::set('ai_voice_enabled', '1');

        $whisper = Mockery::mock(WhisperService::class);
        $whisper->shouldReceive('transcribe')->andReturn('quiero crear un producto nuevo');
        $this->app->instance(WhisperService::class, $whisper);

        app(BotHandler::class)->dispatch([
            'message' => [
                'from' => ['id' => (int) self::CHAT],
                'voice' => ['file_id' => 'abc', 'duration' => 4],
            ],
        ]);

        $this->assertStringStartsWith('nuevo:', $this->pasoActual() ?? '');
    }

    public function test_buscar_un_producto_de_verdad_sigue_buscando(): void
    {
        $this->mandarTexto('mate imperial');

        $this->assertNull(
            $this->pasoNuevo(),
            'Buscar un producto no puede abrir el alta.'
        );
        $this->assertStringContainsString('Mate imperial', implode("\n", $this->enviados));
    }

    public function test_una_frase_que_solo_menciona_la_palabra_producto_no_abre_el_alta(): void
    {
        // Sin verbo de alta: es una búsqueda, aunque diga "producto".
        $this->mandarTexto('producto de limpieza');

        $this->assertNull($this->pasoNuevo(), 'No hay verbo de alta: no se abre el alta.');
    }

    public function test_si_el_mensaje_ya_trae_los_datos_van_al_asistente_y_no_al_paso_uno(): void
    {
        Setting::set('ai_chatbot_enabled', '1');

        $recibido = null;
        $this->app->bind(AgentService::class, function ($app, $params) use (&$recibido) {
            return new class($params['tools'] ?? new ToolRegistry(), $recibido) extends AgentService {
                public function __construct(public ToolRegistry $reg, public &$recibido) {}

                public function run(string $userMessage, array $history, AgentContext $context): array
                {
                    $this->recibido = $userMessage;

                    return ['text' => 'listo', 'messages' => []];
                }
            };
        });

        $this->mandarTexto('crear producto mate imperial 250 de compra 350 de venta 20 unidades');

        $this->assertNotNull($recibido, 'Con datos en el mensaje, tenía que intervenir el asistente.');
        $this->assertStringContainsString('mate imperial', $recibido);
    }

    public function test_estando_en_el_alta_pedir_otra_alta_no_rompe_el_flujo(): void
    {
        $this->mandarTexto('crear un producto');
        $primerPaso = $this->pasoActual();

        $this->mandarTexto('mejor quiero crear un producto nuevo');

        $this->assertStringStartsWith('nuevo:', $this->pasoActual() ?? '');
        $this->assertNotNull($primerPaso);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function mandarTexto(string $texto): void
    {
        app(BotHandler::class)->dispatch([
            'message' => [
                'from' => ['id' => (int) self::CHAT],
                'text' => $texto,
            ],
        ]);
    }

    private function pasoActual(): ?string
    {
        return TelegramConversation::where('chat_id', self::CHAT)->first()?->step;
    }

    /** El paso actual solo si es del alta de producto. */
    private function pasoNuevo(): ?string
    {
        $paso = $this->pasoActual();

        return $paso && str_starts_with($paso, 'nuevo:') ? $paso : null;
    }
}
