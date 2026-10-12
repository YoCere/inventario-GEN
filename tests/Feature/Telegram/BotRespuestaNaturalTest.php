<?php

namespace Tests\Feature\Telegram;

use App\Models\Category;
use App\Models\Location;
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
 * Responder hablando tiene que alcanzar.
 *
 * El caso real: el bot pregunta la categoría, la dueña manda un audio diciendo
 * "ponele mates" y el bot sale a buscar una categoría llamada *"ponele mates"*.
 * Como no existe, ofrece crearla; la dueña dice que no, y el bot vuelve a
 * preguntar lo mismo. Nadie aguanta esa vuelta tres veces.
 *
 * Nadie habla en formulario: la respuesta CONTIENE el dato, no ES el dato.
 */
class BotRespuestaNaturalTest extends TestCase
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
        $this->app->instance(TelegramService::class, $telegram);

        Setting::set('ai_chatbot_enabled', '0');

        Unit::factory()->create(['name' => 'Unidad', 'symbol' => 'u']);
        Category::factory()->create(['name' => 'Mates', 'slug' => 'mates']);
        Category::factory()->create(['name' => 'Bombillas', 'slug' => 'bombillas']);

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

    #[\PHPUnit\Framework\Attributes\DataProvider('formasDeDecirLaCategoria')]
    public function test_contestar_la_categoria_hablando_la_resuelve(string $frase): void
    {
        $this->hastaLaCategoria();

        $this->mandarTexto($frase);

        $this->assertSame(
            'Mates',
            $this->datos()['categoria_nombre'] ?? null,
            "«{$frase}» tenía que resolver a la categoría Mates que ya existe."
        );
    }

    /** @return array<string, array{0: string}> */
    public static function formasDeDecirLaCategoria(): array
    {
        $frases = [
            'mates',
            'Mates',
            'ponele mates',
            'ponle mates',
            'la categoría es mates',
            'la categoria mates',
            'es mates',
            'que sea mates',
            'de la categoría mates',
            'sería mates',
            'va a ser mates',
            'mates por favor',
            'en mates',
            'categoria mates',
        ];

        return array_combine($frases, array_map(fn ($f) => [$f], $frases));
    }

    public function test_el_singular_encuentra_la_categoria_en_plural(): void
    {
        $this->hastaLaCategoria();

        // La dueña dice "mate"; la categoría guardada es "Mates".
        $this->mandarTexto('ponele mate');

        $this->assertSame('Mates', $this->datos()['categoria_nombre'] ?? null);
    }

    public function test_el_plural_encuentra_la_categoria_en_singular(): void
    {
        Category::factory()->create(['name' => 'Cinto', 'slug' => 'cinto']);

        $this->hastaLaCategoria();
        $this->mandarTexto('la categoría es cintos');

        $this->assertSame('Cinto', $this->datos()['categoria_nombre'] ?? null);
    }

    public function test_una_respuesta_sin_dato_no_ofrece_crear_una_categoria_basura(): void
    {
        $this->hastaLaCategoria();

        // Puro relleno: no nombra ninguna categoría.
        $this->mandarTexto('este, la categoría sería, a ver');

        $ultimo = $this->enviados[count($this->enviados) - 1];

        $this->assertStringNotContainsString('¿Crear la categoría', $ultimo);
        $this->assertNull($this->datos()['categoria_pending'] ?? null);
        $this->assertSame('nuevo:categoria', $this->paso(), 'Sigue esperando la categoría.');
    }

    public function test_una_categoria_que_no_existe_se_sigue_pudiendo_crear(): void
    {
        $this->hastaLaCategoria();

        $this->mandarTexto('ponele billeteras');

        $ultimo = $this->enviados[count($this->enviados) - 1];

        $this->assertStringContainsString('billeteras', mb_strtolower($ultimo));
        $this->assertStringContainsString('crear', mb_strtolower($ultimo));
        // Lo que se ofrece crear es el dato limpio, no la frase entera.
        $this->assertStringNotContainsString('ponele', mb_strtolower($ultimo));
    }

    public function test_el_nombre_del_producto_tambien_se_limpia(): void
    {
        app(BotHandler::class)->dispatch([
            'message' => ['from' => ['id' => (int) self::CHAT], 'text' => 'crear un producto'],
        ]);

        $this->mandarTexto('el nombre es mate imperial');

        $this->assertSame('mate imperial', $this->datos()['nombre'] ?? null);
    }

    public function test_elegir_la_categoria_por_numero_sigue_andando(): void
    {
        $this->hastaLaCategoria();

        // "Bombillas" va primero por orden alfabético.
        $this->mandarTexto('1');

        $this->assertSame('Bombillas', $this->datos()['categoria_nombre'] ?? null);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function hastaLaCategoria(): void
    {
        app(BotHandler::class)->dispatch([
            'message' => ['from' => ['id' => (int) self::CHAT], 'text' => 'crear un producto'],
        ]);

        $this->mandarTexto('Mate imperial virola de acero');

        $this->assertSame('nuevo:categoria', $this->paso(), 'El alta tenía que llegar al paso de categoría.');
    }

    private function mandarTexto(string $texto): void
    {
        app(BotHandler::class)->dispatch([
            'message' => ['from' => ['id' => (int) self::CHAT], 'text' => $texto],
        ]);
    }

    private function conversacion(): ?TelegramConversation
    {
        return TelegramConversation::where('chat_id', self::CHAT)->first();
    }

    private function paso(): ?string
    {
        return $this->conversacion()?->step;
    }

    /** @return array<string, mixed> */
    private function datos(): array
    {
        return $this->conversacion()?->data ?? [];
    }
}
