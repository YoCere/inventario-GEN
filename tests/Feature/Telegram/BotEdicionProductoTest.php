<?php

namespace Tests\Feature\Telegram;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TelegramConversation;
use App\Models\TelegramUser;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Messaging\TelegramService;
use App\Services\Telegram\BotAgentHandler;
use App\Services\Telegram\BotProductEditHandler;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Cambiar un producto que YA existe, desde el chat.
 *
 * El pedido real de la dueña: "que se pueda editar productos anteriores ya
 * creados, ya que ahí sale el botón de editar pero al presionarlo desde el bot
 * de Telegram sale que no se puede modificar un producto antiguo… y también que
 * pueda reconocer un producto para editar, ya sea su SKU o su nombre".
 *
 * Cada test de acá falla si eso vuelve a romperse: que el precio quede en lo que
 * se pidió, que el código identifique el producto, que dos nombres parecidos se
 * resuelvan tocando y no adivinando, que un producto inexistente no se cree, que
 * sin permiso no se pueda cambiar nada y que NADA se escriba antes de confirmar.
 */
class BotEdicionProductoTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '555';
    private const OTRO_CHAT = '777';

    /** @var array<int, array{chat_id: string, text: string, keyboard: ?array}> */
    private array $enviados = [];

    private User $duenia;
    private Product $mate;
    private Category $mates;
    private Category $cintos;

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
        $telegram->shouldReceive('editMessageReplyMarkup')->andReturn([]);
        $telegram->shouldReceive('answerCallbackQuery')->andReturn([]);
        $telegram->shouldReceive('sendChatAction')->andReturn([]);
        $telegram->shouldReceive('sendVoice')->andReturn([]);
        $this->app->instance(TelegramService::class, $telegram);

        $this->seed(RolesAndPermissionsSeeder::class);

        // Dos unidades: con una sola no habría nada que elegir y el dato no se
        // ofrecería (así está decidido en el handler).
        Unit::factory()->create(['name' => 'Unidad', 'symbol' => 'u']);
        Unit::factory()->create(['name' => 'Caja', 'symbol' => 'cj']);

        $this->mates = Category::factory()->create(['name' => 'Mates', 'slug' => 'mates']);
        $this->cintos = Category::factory()->create(['name' => 'Cintos', 'slug' => 'cintos']);

        $almacen = Warehouse::create(['name' => 'Almacén', 'is_default' => true]);
        Location::create(['name' => 'Estante', 'warehouse_id' => $almacen->id, 'is_default' => true]);

        $this->duenia = User::factory()->create();
        $this->duenia->givePermissionTo('products.manage');

        TelegramUser::create([
            'chat_id'    => self::CHAT,
            'user_id'    => $this->duenia->id,
            'identifier' => $this->duenia->email,
            'last_login' => now(),
        ]);

        // El producto "viejo": cargado hace meses, como los que fallaban.
        $this->mate = $this->producto('Mate camionero', 'P.251010.ABCD');
    }

    // ── 1. El caso del pedido ────────────────────────────────────────────────

    public function test_cambia_el_precio_de_venta_hablando_normal(): void
    {
        $this->editar('cambiale el precio de venta del mate camionero a 300');

        $this->assertSame('editar:revisar', $this->paso());
        $this->assertSame(26000, $this->mate->fresh()->selling_price, 'Todavía no se tocó nada.');
        $this->assertStringContainsString('260.00 Bs', $this->ultimo());
        $this->assertStringContainsString('300.00 Bs', $this->ultimo());

        $this->boton('guardar');

        $this->assertSame(30000, $this->mate->fresh()->selling_price);
        $this->assertStringContainsString('Listo, lo cambié', $this->ultimo());
        $this->assertStringContainsString('260.00 Bs', $this->ultimo(), 'La confirmación muestra el valor anterior.');
        $this->assertStringContainsString('300.00 Bs', $this->ultimo());
        $this->assertNull($this->conversacion(), 'La edición se cierra al guardar.');
    }

    public function test_entiende_la_frase_ya_recortada_por_el_detector_de_intencion(): void
    {
        // El ruteo del bot saca la intención y pasa "lo que sobró", sin tildes ni
        // muletillas. La edición tiene que entender igual esa forma más seca.
        $this->editar('precio venta mate camionero 300');
        $this->boton('guardar');

        $this->assertSame(30000, $this->mate->fresh()->selling_price);
    }

    // ── 2. Reconocer por código ──────────────────────────────────────────────

    public function test_identifica_el_producto_por_su_codigo(): void
    {
        // Un segundo producto de nombre parecido: si el código no mandara, acá
        // habría que elegir.
        $this->producto('Mate camionero grande', 'P.251010.ZZZZ');

        $this->editar('cambiale el precio del P.251010.ABCD a 300');
        $this->boton('guardar');

        $this->assertSame(30000, $this->mate->fresh()->selling_price);
        $this->assertSame(26000, Product::where('sku', 'P.251010.ZZZZ')->first()->selling_price);
    }

    // ── 3. Varios parecidos: se elige tocando ────────────────────────────────

    public function test_dos_productos_parecidos_se_eligen_tocando_y_no_cambia_nada(): void
    {
        // El de setUp se saca del medio: acá se prueba justo el caso en que
        // ninguno se llama exactamente como lo que se dijo.
        $this->mate->update(['name' => 'Termo acero', 'slug' => 'termo-acero']);

        $chico = $this->producto('Mate camionero chico', 'P.251010.CHIC');
        $grande = $this->producto('Mate camionero grande', 'P.251010.GRAN');

        $this->editar('cambiale el precio de venta del mate camionero a 300');

        $botones = $this->botonesDelUltimo();

        $this->assertContains('edit:abrir:' . $chico->id, $botones);
        $this->assertContains('edit:abrir:' . $grande->id, $botones);
        $this->assertSame(26000, $chico->fresh()->selling_price, 'Nada se modifica hasta elegir.');
        $this->assertSame(26000, $grande->fresh()->selling_price);

        // Al elegir, el cambio pedido sigue vivo y se aplica solo a ese.
        $this->boton('abrir:' . $grande->id);
        $this->boton('guardar');

        $this->assertSame(30000, $grande->fresh()->selling_price);
        $this->assertSame(26000, $chico->fresh()->selling_price);
    }

    // ── 4. Un producto que no existe ─────────────────────────────────────────

    public function test_un_producto_que_no_existe_se_dice_claro_y_no_se_crea_nada(): void
    {
        $cuantos = Product::count();

        $this->editar('cambiale el precio del termo rojo gigante a 300');

        $this->assertStringContainsString('No encontré', $this->ultimo());
        $this->assertSame($cuantos, Product::count(), 'No se crea un producto por error.');
        $this->assertSame(26000, $this->mate->fresh()->selling_price);
        $this->assertSame('editar:buscar', $this->paso(), 'Queda esperando otro nombre o el código.');
    }

    // ── 5. Permisos ──────────────────────────────────────────────────────────

    public function test_sin_permiso_no_se_puede_editar_ni_escribiendo_ni_tocando_el_boton(): void
    {
        $vendedor = User::factory()->create(); // sin products.manage
        TelegramUser::create([
            'chat_id'    => self::OTRO_CHAT,
            'user_id'    => $vendedor->id,
            'identifier' => $vendedor->email,
            'last_login' => now(),
        ]);

        $this->editar('cambiale el precio de venta del mate camionero a 300', self::OTRO_CHAT);

        $this->assertStringContainsString('No tenés permiso', $this->ultimo());
        $this->assertNull($this->conversacion(self::OTRO_CHAT), 'Ni se abre la edición.');

        // Forzar el botón tampoco sirve: el permiso se mira en cada toque.
        $this->boton('campo:precio_venta', self::OTRO_CHAT);
        $this->boton('guardar', self::OTRO_CHAT);

        $this->assertStringContainsString('No tenés permiso', $this->ultimo());
        $this->assertSame(26000, $this->mate->fresh()->selling_price);
    }

    public function test_el_permiso_se_vuelve_a_mirar_al_guardar(): void
    {
        $this->editar('cambiale el precio de venta del mate camionero a 300');
        $this->assertSame('editar:revisar', $this->paso());

        // Se lo deshabilitan mientras tenía el resumen abierto en el chat.
        $this->duenia->revokePermissionTo('products.manage');

        $this->boton('guardar');

        $this->assertSame(26000, $this->mate->fresh()->selling_price);
        $this->assertStringContainsString('No tenés permiso', $this->ultimo());
    }

    public function test_un_boton_de_otra_conversacion_no_edita_desde_otro_chat(): void
    {
        $otro = User::factory()->create();
        $otro->givePermissionTo('products.manage');
        TelegramUser::create([
            'chat_id'    => self::OTRO_CHAT,
            'user_id'    => $otro->id,
            'identifier' => $otro->email,
            'last_login' => now(),
        ]);

        // La dueña deja una edición abierta con un cambio pendiente.
        $this->editar('cambiale el precio de venta del mate camionero a 300');

        // El de al lado toca el mismo botón desde SU chat.
        $this->boton('guardar', self::OTRO_CHAT);

        $this->assertSame(26000, $this->mate->fresh()->selling_price, 'El botón ajeno no guarda nada.');
        $this->assertStringContainsString('ya no está abierta', $this->ultimo());
    }

    // ── 6. El caso que falló en la prueba real ───────────────────────────────

    public function test_se_puede_cambiar_la_categoria_de_un_producto_viejo(): void
    {
        $this->editar('quiero cambiar la categoría del mate camionero');

        $this->assertSame('editar:campo:categoria', $this->paso());
        $this->assertContains('edit:cat:' . $this->cintos->id, $this->botonesDelUltimo());

        // Contestando hablando, no tocando: tiene que funcionar igual.
        $this->texto('ponele cintos');

        $this->assertStringContainsString('Mates', $this->ultimo());
        $this->assertStringContainsString('Cintos', $this->ultimo());
        $this->assertSame($this->mates->id, $this->mate->fresh()->category_id, 'Todavía no se guardó.');

        $this->boton('guardar');

        $this->assertSame($this->cintos->id, $this->mate->fresh()->category_id);
    }

    public function test_tambien_se_elige_la_categoria_tocando(): void
    {
        $this->editar('editar el mate camionero');

        $this->assertSame('editar:menu', $this->paso());
        $this->assertContains('edit:campo:categoria', $this->botonesDelUltimo());

        $this->boton('campo:categoria');
        $this->boton('cat:' . $this->cintos->id);
        $this->boton('guardar');

        $this->assertSame($this->cintos->id, $this->mate->fresh()->category_id);
    }

    // ── 7. Nada se guarda hasta confirmar ────────────────────────────────────

    public function test_nada_se_guarda_hasta_confirmar(): void
    {
        $this->editar('editar el mate camionero');

        $this->boton('campo:precio_venta');
        $this->texto('450');

        $this->boton('campo:cantidad');
        $this->texto('12');

        $this->boton('min:5');

        $antes = $this->mate->fresh();
        $this->assertSame(26000, $antes->selling_price);
        $this->assertSame(30, $antes->quantity);
        $this->assertSame(1, $antes->min_stock);
        $this->assertStringContainsString('Nada se guarda hasta', $this->ultimo());

        $this->boton('guardar');

        $despues = $this->mate->fresh();
        $this->assertSame(45000, $despues->selling_price);
        $this->assertSame(12, $despues->quantity);
        $this->assertSame(5, $despues->min_stock);
    }

    public function test_dejarlo_no_cambia_nada(): void
    {
        $this->editar('cambiale el precio de venta del mate camionero a 300');
        $this->boton('cancelar');

        $this->assertSame(26000, $this->mate->fresh()->selling_price);
        $this->assertNull($this->conversacion());
    }

    // ── El cambio queda en el historial del producto ─────────────────────────

    public function test_el_cambio_de_precio_queda_en_el_historial_con_su_autor(): void
    {
        $this->editar('cambiale el precio de venta del mate camionero a 300');
        $this->boton('guardar');

        $historial = AuditLog::where('action', 'producto.actualizado')
            ->where('auditable_id', $this->mate->id)
            ->first();

        $this->assertNotNull($historial, 'Cambiar un precio tiene que quedar registrado.');
        $this->assertSame(26000, $historial->old_values['selling_price']);
        $this->assertSame(30000, $historial->new_values['selling_price']);
        $this->assertSame($this->duenia->id, $historial->user_id, 'Con el nombre de quien lo cambió.');
    }

    // ── 8. La herramienta nueva, con la IA ───────────────────────────────────

    public function test_la_ia_usa_la_herramienta_nueva_en_vez_de_pedir_datos_en_circulos(): void
    {
        Setting::set('ai_chatbot_enabled', '1');
        Setting::set('ai_provider', 'anthropic');
        Setting::set('anthropic_api_key', 'sk-de-prueba');

        Http::fakeSequence('api.anthropic.com/*')
            ->push([
                'stop_reason' => 'tool_use',
                'content' => [[
                    'type'  => 'tool_use',
                    'id'    => 'toolu_1',
                    'name'  => 'update_product',
                    'input' => ['product' => 'mate camionero', 'selling_price' => 300],
                ]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
            ->push([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => 'Ya está, le cambié el precio.']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]);

        app(BotAgentHandler::class)->handle(self::CHAT, 'cambiale el precio de venta del mate camionero a 300');

        $this->assertSame('editar:revisar', $this->paso());
        $this->assertSame(26000, $this->mate->fresh()->selling_price, 'La IA no guarda sola.');
        $this->assertStringContainsString('300.00 Bs', $this->ultimo());
        $this->assertStringNotContainsString(
            'Ya está, le cambié el precio.',
            $this->ultimo(),
            'El texto del modelo no puede contradecir al resumen: todavía falta confirmar.'
        );

        $this->boton('guardar');

        $this->assertSame(30000, $this->mate->fresh()->selling_price);
    }

    public function test_la_herramienta_no_crea_nada_si_el_producto_no_existe(): void
    {
        $cuantos = Product::count();

        $resultado = app(\App\Services\Agent\Tools\UpdateProductTool::class)->execute(
            ['product' => 'termo rojo gigante', 'selling_price' => 300],
            new \App\Services\Agent\AgentContext(user: $this->duenia, chatId: self::CHAT)
        );

        $this->assertSame('no_encontrado', $resultado['status']);
        $this->assertSame($cuantos, Product::count());
    }

    public function test_la_herramienta_rechaza_un_precio_que_no_es_numero(): void
    {
        $resultado = app(\App\Services\Agent\Tools\UpdateProductTool::class)->execute(
            ['product' => 'mate camionero', 'selling_price' => 'trescientos'],
            new \App\Services\Agent\AgentContext(user: $this->duenia, chatId: self::CHAT)
        );

        $this->assertArrayHasKey('error', $resultado);
        $this->assertSame(26000, $this->mate->fresh()->selling_price);
    }

    // ── Con la IA apagada tiene que funcionar igual ──────────────────────────

    public function test_con_la_ia_apagada_la_edicion_funciona_igual(): void
    {
        Setting::set('ai_chatbot_enabled', '0');
        Http::fake();

        $this->editar('cambiale el precio de venta del mate camionero a 300');
        $this->boton('guardar');

        Http::assertNothingSent();
        $this->assertSame(30000, $this->mate->fresh()->selling_price);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function producto(string $nombre, string $sku): Product
    {
        return Product::factory()->create([
            'name'           => $nombre,
            'sku'            => $sku,
            'slug'           => \Illuminate\Support\Str::slug($nombre),
            'category_id'    => $this->mates->id,
            'unit_id'        => Unit::where('name', 'Unidad')->first()->id,
            'purchase_price' => 20000,
            'selling_price'  => 26000,
            'quantity'       => 30,
            'min_stock'      => 1,
            'is_active'      => true, // la factory lo pone al azar
            'created_at'     => now()->subMonths(8),
        ]);
    }

    private function editar(string $frase, string $chatId = self::CHAT): void
    {
        app(BotProductEditHandler::class)->start($chatId, $frase);
    }

    private function texto(string $texto, string $chatId = self::CHAT): void
    {
        app(BotProductEditHandler::class)->handle($chatId, ['text' => $texto]);
    }

    private function boton(string $accion, string $chatId = self::CHAT): void
    {
        app(BotProductEditHandler::class)->handleButton($chatId, $accion, 4242);
    }

    private function conversacion(string $chatId = self::CHAT): ?TelegramConversation
    {
        return TelegramConversation::where('chat_id', $chatId)->first();
    }

    private function paso(string $chatId = self::CHAT): ?string
    {
        return $this->conversacion($chatId)?->step;
    }

    private function ultimo(): string
    {
        return $this->enviados === [] ? '' : $this->enviados[count($this->enviados) - 1]['text'];
    }

    /** @return array<int, string> los datos de los botones del último mensaje */
    private function botonesDelUltimo(): array
    {
        $filas = $this->enviados === [] ? null : $this->enviados[count($this->enviados) - 1]['keyboard'];

        if (! is_array($filas)) {
            return [];
        }

        $datos = [];

        foreach ($filas as $fila) {
            foreach ($fila as $boton) {
                $datos[] = $boton['callback_data'];
            }
        }

        return $datos;
    }
}
