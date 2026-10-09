<?php

namespace Tests\Feature\Telegram;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\TelegramUser;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Agent\AgentContext;
use App\Services\Agent\AgentService;
use App\Services\Agent\ToolRegistry;
use App\Services\Agent\Tools\StartProductCreationTool;
use App\Services\Messaging\TelegramService;
use App\Shop\Models\ProductImage;
use App\Services\Telegram\BotAgentHandler;
use App\Services\Telegram\BotHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Varias fotos por producto desde el bot.
 *
 * El caso real: la talabartera hace el mismo cinto en varios colores y quiere
 * mandar una foto de cada uno, sin tener que escribir "omitir" para terminar.
 */
class BotProductPhotosTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '555';

    /** @var array<int, array{text: string, keyboard: ?array}> */
    private array $enviados = [];

    /** JPEG real reutilizado como "foto descargada de Telegram". */
    private string $jpeg;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        // El objeto tiene que seguir vivo mientras se lee: al descartarlo borra su archivo temporal.
        $fake = UploadedFile::fake()->image('foto.jpg', 600, 600);
        $this->jpeg = file_get_contents($fake->getPathname());

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
        // Cada foto "descargada" de Telegram es un JPEG real para que el
        // procesamiento de variantes se ejecute de verdad.
        $telegram->shouldReceive('getFile')->andReturn('ruta/en/telegram.jpg');
        $telegram->shouldReceive('downloadFile')->andReturnUsing(fn () => $this->jpeg);
        $this->app->instance(TelegramService::class, $telegram);

        Unit::factory()->create(['name' => 'Unidad', 'symbol' => 'u']);
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

    public function test_tres_fotos_quedan_en_la_galeria_y_la_primera_es_la_principal(): void
    {
        $this->altaDictadaHastaLasFotos();

        $this->mandarFoto();
        $this->mandarFoto();
        $this->mandarFoto();

        $this->toqueDeBoton('prod:fotos:listo');
        $this->toqueDeBoton('prod:guardar');

        $producto = Product::firstOrFail();
        $imagenes = ProductImage::where('product_id', $producto->id)->orderBy('sort_order')->get();

        $this->assertCount(3, $imagenes, 'Las tres fotos tenían que quedar guardadas.');
        $this->assertTrue($imagenes[0]->is_primary, 'La primera foto es la principal.');
        $this->assertFalse($imagenes[1]->is_primary);
        $this->assertFalse($imagenes[2]->is_primary);
    }

    public function test_despues_de_cada_foto_ofrece_agregar_otra_o_terminar(): void
    {
        $this->altaDictadaHastaLasFotos();

        $this->mandarFoto();

        $ultimo = $this->ultimoMensaje();
        $datosDeBotones = $this->datosDeBotones($ultimo['keyboard']);

        $this->assertContains('prod:fotos:otra', $datosDeBotones);
        $this->assertContains('prod:fotos:listo', $datosDeBotones);
    }

    public function test_terminar_sin_fotos_sigue_permitido(): void
    {
        $this->altaDictadaHastaLasFotos();

        $this->toqueDeBoton('prod:fotos:listo');
        $this->toqueDeBoton('prod:guardar');

        $producto = Product::firstOrFail();

        $this->assertSame(0, ProductImage::where('product_id', $producto->id)->count());
    }

    public function test_el_resumen_dice_cuantas_fotos_hay(): void
    {
        $this->altaDictadaHastaLasFotos();

        $this->mandarFoto();
        $this->mandarFoto();
        $this->toqueDeBoton('prod:fotos:listo');

        $this->assertStringContainsString('Fotos: 2 fotos', $this->ultimoMensaje()['text']);
    }

    public function test_un_archivo_que_no_es_imagen_se_rechaza_sin_romper_el_alta(): void
    {
        $this->altaDictadaHastaLasFotos();

        // Telegram manda como "photo" algo que no es una imagen.
        $this->jpeg = 'esto no es una imagen';

        $this->mandarFoto();

        $textos = collect($this->enviados)->pluck('text')->implode(' ');
        $this->assertStringContainsString('no es una imagen', $textos);

        // El alta sigue viva: se puede terminar y guardar igual.
        $this->toqueDeBoton('prod:fotos:listo');
        $this->toqueDeBoton('prod:guardar');

        $this->assertSame(1, Product::count());
    }

    public function test_al_llegar_al_maximo_avisa_y_no_guarda_mas(): void
    {
        $this->altaDictadaHastaLasFotos();

        for ($i = 0; $i < 6; $i++) {
            $this->mandarFoto();
        }

        $textos = collect($this->enviados)->pluck('text')->implode(' ');
        $this->assertStringContainsString('máximo', $textos);

        $this->toqueDeBoton('prod:fotos:listo');
        $this->toqueDeBoton('prod:guardar');

        $producto = Product::firstOrFail();
        $this->assertSame(5, ProductImage::where('product_id', $producto->id)->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function mandarFoto(): void
    {
        app(BotHandler::class)->dispatch([
            'message' => [
                'from' => ['id' => (int) self::CHAT],
                'photo' => [
                    ['file_id' => 'chico', 'width' => 90],
                    ['file_id' => 'grande', 'width' => 1280],
                ],
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

    /** Deja el alta dictada parada justo en el paso de fotos. */
    private function altaDictadaHastaLasFotos(): void
    {
        $campos = [
            'name' => 'Cinto de cuero',
            'category' => 'Cintos',
            'purchase_price' => 350,
            'selling_price' => 450,
            'quantity' => 30,
        ];

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

        app(BotAgentHandler::class)->handleVoice(self::CHAT, 'crear producto cinto de cuero');
    }

    /** @return array<int, array{text: string, keyboard: ?array}> */
    private function ultimoMensaje(): array
    {
        return $this->enviados[count($this->enviados) - 1];
    }

    /** @return array<int, string> */
    private function datosDeBotones(?array $keyboard): array
    {
        $datos = [];

        // TelegramKeyboard::toArray() devuelve las filas directas.
        foreach ($keyboard ?? [] as $fila) {
            foreach ($fila as $boton) {
                $datos[] = $boton['callback_data'] ?? '';
            }
        }

        return $datos;
    }
}
