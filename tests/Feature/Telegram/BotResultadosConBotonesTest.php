<?php

namespace Tests\Feature\Telegram;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TelegramConversation;
use App\Models\TelegramUser;
use App\Models\Unit;
use App\Models\User;
use App\Services\Agent\VisionService;
use App\Services\Messaging\TelegramService;
use App\Services\Telegram\BotHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Después de buscar, la salida está a la vista.
 *
 * Antes, lo único que decía el bot tras una búsqueda era "escribí el número", y
 * tras una foto que no reconoció no decía nada más: la persona se quedaba
 * mirando una lista sin saber qué podía hacer con ella.
 *
 * Dos reglas que estos tests cuidan:
 *  - los botones se suman, no reemplazan: escribir el número sigue valiendo;
 *  - si la foto no encontró nada, dar de alta el producto reusa ESA foto, sin
 *    pedirle a la persona que la saque de nuevo.
 */
class BotResultadosConBotonesTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '555';

    /** Un PNG de 1x1: lo mínimo que el guardado reconoce como imagen. */
    private const PNG_MINIMO = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** Mensajes que mandó el bot: texto y botones. */
    private array $enviados = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->andReturnUsing(
            function (string $chatId, string $text, string $parseMode = 'HTML', ?array $keyboard = null) {
                $this->enviados[] = ['text' => $text, 'keyboard' => $keyboard];

                return [];
            }
        );
        $telegram->shouldReceive('sendPhoto')->andReturnUsing(
            function (string $chatId, string $filePath, string $caption = '', ?array $keyboard = null) {
                $this->enviados[] = ['text' => $caption, 'keyboard' => $keyboard];

                return [];
            }
        );
        $telegram->shouldReceive('sendChatAction')->andReturn([]);
        $telegram->shouldReceive('answerCallbackQuery')->andReturn([]);
        $telegram->shouldReceive('getFile')->andReturn('fotos/producto.png');
        $telegram->shouldReceive('downloadFile')->andReturn(base64_decode(self::PNG_MINIMO));
        $this->app->instance(TelegramService::class, $telegram);

        Setting::set('ai_chatbot_enabled', '0');
        Setting::set('ai_search_enabled', '0');

        Unit::factory()->create(['name' => 'Unidad', 'symbol' => 'u']);
        Category::factory()->create(['name' => 'Sombreros', 'slug' => 'sombreros']);

        $duenia = User::factory()->create();
        TelegramUser::create([
            'chat_id'    => self::CHAT,
            'user_id'    => $duenia->id,
            'identifier' => $duenia->email,
            'last_login' => now(),
        ]);
    }

    public function test_la_lista_trae_botones_y_el_numero_sigue_funcionando(): void
    {
        $primero = $this->producto('Sombrero de fieltro', 'SOM-001');
        $this->producto('Sombrero de paja', 'SOM-002');

        $this->mandarTexto('sombrero');

        $botones = $this->botonesDelUltimo();
        $this->assertStringContainsString('buscar:elegir:1', $botones, 'Cada resultado se tiene que poder tocar.');
        $this->assertStringContainsString('buscar:otro', $botones, 'Y tiene que haber salida para buscar otra cosa.');

        // Lo de siempre: escribir el número.
        $this->mandarTexto('1');

        $conversacion = TelegramConversation::where('chat_id', self::CHAT)->first();
        $this->assertSame('busqueda:resultado', $conversacion?->step);
        $this->assertSame($primero->id, $conversacion?->data['product_id'] ?? null);
    }

    public function test_la_ficha_trae_el_boton_de_editar_con_el_producto(): void
    {
        $producto = $this->producto('Mate imperial virola de acero', 'MAT-001');

        $this->mandarTexto('mate imperial virola de acero');

        $this->assertStringContainsString(
            'edit:abrir:' . $producto->id,
            $this->botonesDelUltimo(),
            'El botón de editar tiene que llevar el producto que se está mirando.'
        );
        $this->assertStringContainsString('buscar:vender', $this->botonesDelUltimo());
    }

    public function test_tras_una_foto_sin_coincidencias_el_alta_reusa_esa_foto(): void
    {
        $vision = Mockery::mock(VisionService::class);
        $vision->shouldReceive('isEnabled')->andReturn(true);
        $vision->shouldReceive('describeProductImage')->andReturn('chamarra importada rarisima');
        $this->app->instance(VisionService::class, $vision);

        $this->mandarFoto('foto-de-la-duenia');

        $this->assertStringContainsString(
            'buscar:nuevo',
            $this->botonesDelUltimo(),
            'Si la foto no encontró nada, la salida es dar de alta el producto.'
        );

        $this->tocarBoton('buscar:nuevo');

        $conversacion = TelegramConversation::where('chat_id', self::CHAT)->first();
        $fotos = $conversacion?->data['fotos'] ?? [];

        $this->assertStringStartsWith('nuevo:', $conversacion?->step ?? '', 'Tenía que abrirse el alta.');
        $this->assertCount(1, $fotos, 'El alta tiene que arrancar con la foto que la persona ya mandó.');
        $this->assertTrue(
            Storage::disk('public')->exists($fotos[0]),
            'La foto reusada tiene que estar guardada donde el alta la busca.'
        );
        $this->assertSame($fotos[0], $conversacion?->data['foto_path'] ?? null);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function producto(string $nombre, string $sku): Product
    {
        return Product::factory()->create([
            'name'          => $nombre,
            'sku'           => $sku,
            'selling_price' => 10000,
            'quantity'      => 5,
            'min_stock'     => 1,
            'is_active'     => true,
            'image_path'    => null,
        ]);
    }

    private function mandarTexto(string $texto): void
    {
        app(BotHandler::class)->dispatch([
            'message' => ['from' => ['id' => (int) self::CHAT], 'text' => $texto],
        ]);
    }

    private function mandarFoto(string $fileId): void
    {
        app(BotHandler::class)->dispatch([
            'message' => [
                'from'  => ['id' => (int) self::CHAT],
                'photo' => [['file_id' => $fileId, 'file_size' => 100]],
            ],
        ]);
    }

    private function tocarBoton(string $data): void
    {
        app(BotHandler::class)->dispatch([
            'callback_query' => [
                'id'      => 'toque-1',
                'from'    => ['id' => (int) self::CHAT],
                'message' => ['message_id' => 99, 'chat' => ['id' => (int) self::CHAT]],
                'data'    => $data,
            ],
        ]);
    }

    private function botonesDelUltimo(): string
    {
        $ultimo = $this->enviados[count($this->enviados) - 1];

        return json_encode($ultimo['keyboard'], JSON_UNESCAPED_UNICODE) ?: '';
    }
}
