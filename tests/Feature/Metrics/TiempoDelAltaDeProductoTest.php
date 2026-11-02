<?php

namespace Tests\Feature\Metrics;

use App\Livewire\Products\ProductForm;
use App\Models\ActivityTiming;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TelegramUser;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Messaging\TelegramService;
use App\Services\Telegram\BotHandler;
use App\Support\Metrics\TimedActivity;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Cuánto se tarda en cargar un producto, medido de verdad.
 *
 * El dueño quiere poder decirle a un cliente nuevo "cargar un producto te lleva
 * un minuto". Para eso el reloj tiene que arrancar cuando la persona empieza (la
 * primera pregunta del bot, o el formulario al abrirse) y no cuando se guarda,
 * que es casi instantáneo y no mide nada.
 */
class TiempoDelAltaDeProductoTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '777';

    /** Arranque del reloj en todos los casos, para que las cuentas sean a mano. */
    private const T0 = '2026-10-28 14:00:00';

    private User $duenia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->andReturn([]);
        $telegram->shouldReceive('sendChatAction')->andReturn([]);
        $telegram->shouldReceive('answerCallbackQuery')->andReturn([]);
        $telegram->shouldReceive('editMessageReplyMarkup')->andReturn([]);
        $this->app->instance(TelegramService::class, $telegram);

        // Sin IA: el alta tiene que ser determinista paso a paso.
        Setting::set('ai_chatbot_enabled', '0');

        Unit::factory()->create(['name' => 'Unidad', 'symbol' => 'u']);
        Category::factory()->create(['name' => 'Mates', 'slug' => 'mates']);

        $almacen = Warehouse::create(['name' => 'Almacén', 'is_default' => true]);
        Location::create(['name' => 'Estante', 'warehouse_id' => $almacen->id, 'is_default' => true]);

        $this->duenia = User::factory()->create(['email_verified_at' => now()]);
        $this->duenia->assignRole('admin');

        TelegramUser::create([
            'chat_id' => self::CHAT,
            'user_id' => $this->duenia->id,
            'identifier' => $this->duenia->email,
            'last_login' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    // ── Alta por el bot ──────────────────────────────────────────────────────

    public function test_un_alta_por_el_bot_queda_medida_con_su_canal_y_el_tiempo_de_cada_paso(): void
    {
        // Diez segundos por pregunta y cinco revisando el resumen: 75 en total.
        $this->altaPorElBot(segundosPorPaso: 10, segundosDeRevision: 5);

        $this->assertSame(1, Product::count(), 'El alta tiene que haber creado el producto.');

        $medicion = ActivityTiming::sole();

        $this->assertSame(TimedActivity::ALTA_PRODUCTO, $medicion->activity);
        $this->assertSame(TimedActivity::CANAL_BOT, $medicion->channel);
        $this->assertSame($this->duenia->id, $medicion->user_id);
        $this->assertFalse($medicion->discarded, 'Un alta normal no se descarta.');
        $this->assertSame(75, $medicion->seconds);

        // El tiempo por paso es lo que muestra dónde se traba la gente.
        $pasos = $medicion->steps;
        $this->assertSame(10, $pasos['nuevo:nombre']);
        $this->assertSame(10, $pasos['nuevo:categoria']);
        $this->assertSame(10, $pasos['nuevo:precio_compra']);
        $this->assertSame(10, $pasos['nuevo:precio_venta']);
        $this->assertSame(10, $pasos['nuevo:cantidad']);
        $this->assertSame(10, $pasos['nuevo:stock_minimo']);
        $this->assertSame(10, $pasos['nuevo:foto']);
        $this->assertSame(5, $pasos['nuevo:revisar']);

        // La suma de los pasos es el total: ningún tramo queda sin atribuir.
        $this->assertSame($medicion->seconds, array_sum($pasos));
    }

    public function test_un_alta_abandonada_por_el_bot_no_cuenta_como_una_carga_larguisima(): void
    {
        // Contesta el nombre y se va; vuelve veinte minutos después y termina.
        // Veinte y no cuarenta porque a los 30 minutos el bot da por vencida la
        // conversación: ahí ya no hay alta que medir, hay un alta que no existió.
        $this->altaPorElBot(segundosPorPaso: 10, segundosDeRevision: 5, pausaEnElPrimerPaso: 20 * 60);

        $this->assertSame(1, Product::count(), 'Aunque haya abandonado y vuelto, el producto se crea.');

        $medicion = ActivityTiming::sole();

        $this->assertTrue(
            $medicion->discarded,
            'Un alta que pasó el tope se guarda marcada, no se suma al promedio.'
        );
        $this->assertGreaterThan(
            TimedActivity::topeSegundos(TimedActivity::ALTA_PRODUCTO),
            $medicion->seconds
        );
    }

    public function test_si_falla_la_medicion_el_alta_por_el_bot_se_completa_igual(): void
    {
        // La forma más honesta de simular "la tabla de mediciones no responde".
        Schema::drop('activity_timings');

        $this->altaPorElBot(segundosPorPaso: 10, segundosDeRevision: 5);

        $this->assertSame(1, Product::count(), 'La medición no puede hacer perder un producto.');
        $this->assertSame('Mate imperial', Product::first()->name);
    }

    // ── Alta por la web ──────────────────────────────────────────────────────

    public function test_un_alta_por_la_web_queda_medida_con_su_canal(): void
    {
        $this->altaPorLaWeb(segundos: 90);

        $this->assertSame(1, Product::count());

        $medicion = ActivityTiming::sole();

        $this->assertSame(TimedActivity::ALTA_PRODUCTO, $medicion->activity);
        $this->assertSame(TimedActivity::CANAL_WEB, $medicion->channel);
        $this->assertSame($this->duenia->id, $medicion->user_id);
        $this->assertSame(90, $medicion->seconds);
        $this->assertFalse($medicion->discarded);
        // La pantalla web no tiene pasos: eso es del bot.
        $this->assertNull($medicion->steps);
    }

    public function test_un_formulario_web_dejado_abierto_una_hora_no_cuenta(): void
    {
        $this->altaPorLaWeb(segundos: 60 * 60);

        $this->assertSame(1, Product::count(), 'El producto se crea igual.');
        $this->assertTrue(ActivityTiming::sole()->discarded);
    }

    public function test_editar_un_producto_no_se_cuenta_como_un_alta(): void
    {
        $producto = Product::factory()->create(['is_active' => true]);

        Livewire::actingAs($this->duenia)
            ->test(ProductForm::class)
            ->call('edit', $producto)
            ->set('name', 'Otro nombre')
            ->call('save');

        $this->assertSame(0, ActivityTiming::count(), 'Editar no es dar de alta.');
    }

    public function test_si_falla_la_medicion_el_alta_por_la_web_se_completa_igual(): void
    {
        Schema::drop('activity_timings');

        $this->altaPorLaWeb(segundos: 90);

        $this->assertSame(1, Product::count(), 'La medición no puede hacer perder un producto.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Alta completa por el bot, escribiendo, con el reloj controlado.
     *
     * @param  int  $pausaEnElPrimerPaso  segundos extra entre la primera pregunta y la respuesta
     */
    private function altaPorElBot(int $segundosPorPaso, int $segundosDeRevision, int $pausaEnElPrimerPaso = 0): void
    {
        $t0 = Carbon::parse(self::T0);

        Carbon::setTestNow($t0);
        $this->mandarTexto('crear un producto');

        $respuestas = ['Mate imperial', 'mates', '250', '350', '20', '5', 'omitir'];
        $transcurrido = 0;

        foreach ($respuestas as $i => $respuesta) {
            $transcurrido += $segundosPorPaso + ($i === 0 ? $pausaEnElPrimerPaso : 0);
            Carbon::setTestNow($t0->copy()->addSeconds($transcurrido));
            $this->mandarTexto($respuesta);
        }

        Carbon::setTestNow($t0->copy()->addSeconds($transcurrido + $segundosDeRevision));
        $this->toqueDeBoton('prod:guardar');
    }

    /** Alta completa por el formulario web, con el reloj controlado. */
    private function altaPorLaWeb(int $segundos): void
    {
        $t0 = Carbon::parse(self::T0);
        Carbon::setTestNow($t0);

        $componente = Livewire::actingAs($this->duenia)
            ->test(ProductForm::class)
            ->call('create')
            ->set('name', 'Mate imperial')
            ->set('category_id', Category::first()->id)
            ->set('unit_id', Unit::first()->id)
            ->set('purchase_price', 25000)
            ->set('selling_price', 35000)
            ->set('quantity', 20)
            ->set('min_stock', 5);

        Carbon::setTestNow($t0->copy()->addSeconds($segundos));

        $componente->call('save');
    }

    private function mandarTexto(string $texto): void
    {
        app(BotHandler::class)->dispatch([
            'message' => ['from' => ['id' => (int) self::CHAT], 'text' => $texto],
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
}
