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
use App\Services\Messaging\TelegramService;
use App\Services\Telegram\BotHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Nadie queda atrapado hablando con la IA.
 *
 * El caso real: el dueño probó el bot con la persona que usa el sistema todos
 * los días. Buscó algo que no estaba, le contestó el asistente, y desde ahí
 * TODO lo que escribió se lo volvió a contestar el asistente: escribió "Crear
 * producto" tres veces y el alta nunca se abrió, porque el bot, una vez en la
 * charla, dejaba de escuchar cualquier otra cosa.
 *
 * Lo que estos tests fijan: mientras la persona no esté llenando un
 * formulario, una orden nueva (vender, dar de alta, corregir) se atiende antes
 * que el paso en curso. Y al revés: una pregunta de verdad sigue yendo a la IA.
 */
class BotNoQuedaAtrapadaTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '555';

    /** Lo que el bot mandó: texto y botones de cada mensaje. */
    private array $enviados = [];

    /** Lo que se le llegó a preguntar al asistente de IA. */
    public array $preguntadoALaIa = [];

    private Location $ubicacion;

    protected function setUp(): void
    {
        parent::setUp();

        // La venta de verdad necesita plan de cuentas y período abierto.
        $this->seed([
            \Database\Seeders\AccountingPeriodSeeder::class,
            \Database\Seeders\ChartOfAccountSeeder::class,
            \Database\Seeders\SettingSeeder::class,
        ]);

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

        Unit::factory()->create(['name' => 'Unidad', 'symbol' => 'u']);
        Category::factory()->create(['name' => 'Mates', 'slug' => 'mates']);
        $almacen = Warehouse::create(['name' => 'Almacén', 'code' => 'ALM', 'is_default' => true]);
        $this->ubicacion = Location::create([
            'name' => 'Estante', 'code' => 'EST', 'warehouse_id' => $almacen->id, 'is_default' => true,
        ]);

        $duenia = User::factory()->staff()->create();
        TelegramUser::create([
            'chat_id'    => self::CHAT,
            'user_id'    => $duenia->id,
            'identifier' => $duenia->email,
            'last_login' => now(),
        ]);
    }

    public function test_la_secuencia_que_la_dejo_atrapada_ahora_termina_en_el_alta(): void
    {
        $this->conAsistente();

        // 1. Busca algo que no está en el catálogo: contesta el asistente.
        $this->mandarTexto('tengo pantalon de vestir gris talle 44');
        $this->assertSame('agent:active', $this->pasoActual(), 'La reproducción arranca con la charla abierta.');

        // 2. Pide el alta, igual que lo hizo ella.
        $this->mandarTexto('Crear producto');

        $this->assertStringStartsWith(
            'nuevo:',
            $this->pasoActual() ?? '',
            'Estando en la charla con la IA, "Crear producto" tiene que abrir el alta.'
        );
        $this->assertNotContains(
            'Crear producto',
            $this->preguntadoALaIa,
            'El pedido de alta no se le pregunta a la IA: lo resuelve el bot.'
        );
    }

    public function test_hablando_con_la_ia_una_orden_de_venta_vende_de_verdad(): void
    {
        $producto = $this->productoConStock('Mate de cuero cosido', precio: 2500);
        $this->enCharlaConLaIa();

        $this->mandarTexto('vende 2 mates a 20');

        $this->assertSame(1, Sale::count(), 'La venta tenía que concretarse, no quedar en una charla.');
        $this->assertSame(8, $producto->fresh()->quantity, 'Tenía que descontar las 2 unidades vendidas.');
    }

    public function test_mirando_una_lista_de_resultados_se_puede_pedir_el_alta(): void
    {
        $this->productoConStock('Sombrero de fieltro');
        $this->productoConStock('Sombrero de paja');

        $this->mandarTexto('sombrero');
        $this->assertSame('busqueda:multiple', $this->pasoActual(), 'Dos coincidencias dan lista para elegir.');

        $this->mandarTexto('quiero crear un producto');

        $this->assertStringStartsWith(
            'nuevo:',
            $this->pasoActual() ?? '',
            'Desde la lista de resultados, pedir el alta tiene que abrirla.'
        );
    }

    public function test_una_pregunta_de_verdad_sigue_yendo_al_asistente(): void
    {
        $this->conAsistente();
        $this->enCharlaConLaIa();

        $this->mandarTexto('cuánto vendí hoy');

        $this->assertContains(
            'cuánto vendí hoy',
            $this->preguntadoALaIa,
            'Una pregunta del negocio tiene que seguir llegando al asistente.'
        );
        $this->assertNull($this->pasoNuevo(), 'Preguntar no abre ningún alta.');
    }

    public function test_pedir_corregir_un_producto_llega_al_flujo_de_edicion(): void
    {
        $pedidos = [];
        $edicion = Mockery::mock('App\Services\Telegram\BotProductEditHandler');
        $edicion->shouldReceive('start')->andReturnUsing(function (string $chatId, string $frase = '') use (&$pedidos) {
            $pedidos[] = $frase;
        });
        $this->app->instance('App\Services\Telegram\BotProductEditHandler', $edicion);

        $this->enCharlaConLaIa();
        $this->mandarTexto('cambiale el precio del mate camionero');

        // El dato nombrado viaja junto al producto. En el alta "producto" es
        // relleno y se descarta, pero acá "precio" es QUÉ hay que cambiar:
        // perderlo obliga al flujo de edición a volver a preguntarlo, que es
        // justo la repregunta que estamos sacando del medio.
        $this->assertSame(
            ['precio mate camionero'],
            $pedidos,
            'El pedido de corregir va al flujo de edición con el dato y el producto nombrados.'
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Deja el asistente encendido y anotando lo que se le pregunta. */
    private function conAsistente(): void
    {
        Setting::set('ai_chatbot_enabled', '1');

        $this->app->bind(AgentService::class, function ($app, $params) {
            return new class($params['tools'] ?? new ToolRegistry(), $this) extends AgentService {
                public function __construct(public ToolRegistry $registro, private $prueba) {}

                public function run(string $userMessage, array $history, AgentContext $context): array
                {
                    $this->prueba->preguntadoALaIa[] = $userMessage;

                    return [
                        'text'     => 'Hoy vendiste Bs 120.',
                        'messages' => [['role' => 'user', 'content' => $userMessage]],
                    ];
                }
            };
        });
    }

    /** Deja la conversación como la deja el asistente después de contestar. */
    private function enCharlaConLaIa(): void
    {
        TelegramConversation::getOrCreate(self::CHAT)->update([
            'step'       => 'agent:active',
            'data'       => ['agent_history' => [['role' => 'user', 'content' => 'hola']]],
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    private function productoConStock(string $nombre, int $precio = 4000): Product
    {
        $producto = Product::factory()->create([
            'name'           => $nombre,
            'selling_price'  => $precio,
            'purchase_price' => 1000,
            'quantity'       => 0,
            'is_active'      => true,
        ]);

        ProductStock::create([
            'product_id'  => $producto->id,
            'location_id' => $this->ubicacion->id,
            'quantity'    => 10,
        ]);
        $producto->update(['quantity' => 10]);

        return $producto;
    }

    private function mandarTexto(string $texto): void
    {
        app(BotHandler::class)->dispatch([
            'message' => ['from' => ['id' => (int) self::CHAT], 'text' => $texto],
        ]);
    }

    private function pasoActual(): ?string
    {
        return TelegramConversation::where('chat_id', self::CHAT)->first()?->step;
    }

    private function pasoNuevo(): ?string
    {
        $paso = $this->pasoActual();

        return $paso && str_starts_with($paso, 'nuevo:') ? $paso : null;
    }
}
