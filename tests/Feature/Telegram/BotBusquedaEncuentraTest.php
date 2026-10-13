<?php

namespace Tests\Feature\Telegram;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TelegramConversation;
use App\Models\TelegramUser;
use App\Models\Unit;
use App\Models\User;
use App\Services\Messaging\TelegramService;
use App\Services\Telegram\BotHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * El bot encuentra lo que existe, y lo muestra todo.
 *
 * Dos cosas que el dueño venía diciendo y eran ciertas:
 *
 * 1. "No encuentra la mitad de las cosas". Cualquier nombre con ñ o tilde
 *    nunca llegaba a la base: el bot contaba las palabras con una función que
 *    trabaja byte por byte, "chaqueño" le contaba como dos, la frase le daba
 *    más de 4 palabras y la trataba como charla para el asistente.
 * 2. "No muestra todos". Los encontraba y mostraba cinco, sin avisar que había
 *    más.
 *
 * Todo esto con el asistente APAGADO: tiene que andar en una instalación recién
 * hecha, sin claves de IA.
 */
class BotBusquedaEncuentraTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '555';

    /** Mensajes que mandó el bot: texto y botones. */
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

        // Sin IA: la búsqueda no puede depender de ella.
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

    public function test_un_nombre_largo_con_enie_se_busca_en_el_catalogo(): void
    {
        $this->producto('Sombrero chaqueño costura a mano', 'SOM-001');

        $this->mandarTexto('sombrero chaqueño costura a mano');

        $this->assertStringContainsString(
            'Sombrero chaqueño costura a mano',
            $this->todoLoDicho(),
            'Un nombre de cinco palabras con ñ tiene que buscarse, no irse a la IA.'
        );
    }

    public function test_doce_coincidencias_muestran_mas_de_cinco_y_dejan_pedir_el_resto(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->producto('Sombrero chaqueño modelo ' . $i, 'SOM-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT));
        }

        $this->mandarTexto('sombrero');

        $lista = $this->ultimoMensaje();

        $this->assertStringContainsString('6. ', $lista['text'], 'Tiene que mostrar más de cinco.');
        $this->assertStringContainsString('8. ', $lista['text']);
        $this->assertStringContainsString(
            'buscar:mas',
            json_encode($lista['keyboard'], JSON_UNESCAPED_UNICODE),
            'Si quedan más, tiene que haber un botón para pedirlos.'
        );

        // Y escribiéndolo también, para quien no usa botones.
        $this->mandarTexto('ver más');

        $this->assertStringContainsString(
            '12. ',
            $this->ultimoMensaje()['text'],
            '"Ver más" tiene que traer el resto de la lista.'
        );
    }

    public function test_el_codigo_exacto_le_gana_a_cualquier_parecido(): void
    {
        $buscado = $this->producto('Bombilla de alpaca', 'MATE-77');
        $this->producto('Mate 77 camionero', 'MAT-999');

        // En minúsculas, como lo escribe cualquiera que lo copia del cartel.
        $this->mandarTexto('mate-77');

        $this->assertStringContainsString(
            'Bombilla de alpaca',
            $this->todoLoDicho(),
            'El código exacto manda, aunque otro producto se llame parecido.'
        );
        $this->assertSame(
            $buscado->id,
            TelegramConversation::where('chat_id', self::CHAT)->first()?->data['product_id'] ?? null,
            'El producto del código es el que queda elegido.'
        );
    }

    public function test_dos_productos_con_el_mismo_nombre_se_distinguen(): void
    {
        $this->producto('Sombrero chaqueño', 'SOM-AAA', 15000);
        $this->producto('Sombrero chaqueño', 'SOM-BBB', 22000);

        $this->mandarTexto('Sombrero chaqueño');

        $lista = $this->ultimoMensaje()['text'];

        $this->assertStringContainsString('SOM-AAA', $lista, 'Hace falta el código para saber cuál es cuál.');
        $this->assertStringContainsString('SOM-BBB', $lista);
        $this->assertStringContainsString('150.00', $lista, 'Y el precio, que es lo que mira la dueña.');
        $this->assertStringContainsString('220.00', $lista);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function producto(string $nombre, string $sku, int $precio = 10000): Product
    {
        return Product::factory()->create([
            'name'          => $nombre,
            'sku'           => $sku,
            'selling_price' => $precio,
            'quantity'      => 5,
            'min_stock'     => 1,
            'is_active'     => true,
        ]);
    }

    private function mandarTexto(string $texto): void
    {
        app(BotHandler::class)->dispatch([
            'message' => ['from' => ['id' => (int) self::CHAT], 'text' => $texto],
        ]);
    }

    private function todoLoDicho(): string
    {
        return implode("\n", array_column($this->enviados, 'text'));
    }

    /** @return array{text: string, keyboard: ?array} */
    private function ultimoMensaje(): array
    {
        return $this->enviados[count($this->enviados) - 1];
    }
}
