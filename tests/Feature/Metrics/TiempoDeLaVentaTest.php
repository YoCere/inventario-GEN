<?php

namespace Tests\Feature\Metrics;

use App\Models\ActivityTiming;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Metrics\TimedActivity;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cuánto se tarda en cobrar una venta.
 *
 * El reloj arranca cuando la pantalla de cobro queda lista para atender, y
 * vuelve a arrancar en cuanto se cierra una venta: así la segunda venta del día
 * no arrastra el tiempo de la primera.
 *
 * Lo más importante de este archivo es el último test: si la medición falla, la
 * venta igual se cobra. Es plata real.
 */
class TiempoDeLaVentaTest extends TestCase
{
    use RefreshDatabase;

    private const T0 = '2026-10-28 14:00:00';

    private User $vendedor;
    private Product $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesAndPermissionsSeeder::class,
            AccountingPeriodSeeder::class,
            ChartOfAccountSeeder::class,
            SettingSeeder::class,
        ]);

        $this->vendedor = User::factory()->create(['email_verified_at' => now()]);
        $this->vendedor->assignRole('admin');

        $almacen = Warehouse::create(['name' => 'Almacén', 'code' => 'ALM']);
        $ubicacion = Location::create(['name' => 'Estante', 'code' => 'EST', 'warehouse_id' => $almacen->id]);

        $this->producto = Product::factory()->create([
            'is_active' => true,
            'selling_price' => 10000,
            'purchase_price' => 6000,
            'quantity' => 0,
        ]);

        ProductStock::create([
            'product_id' => $this->producto->id,
            'location_id' => $ubicacion->id,
            'quantity' => 50,
        ]);
        $this->producto->update(['quantity' => 50]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    public function test_una_venta_por_la_pantalla_de_cobro_queda_medida(): void
    {
        $t0 = Carbon::parse(self::T0);

        Carbon::setTestNow($t0);
        $this->abrirPantallaDeCobro();

        // Cuarenta segundos entre que la pantalla quedó lista y el cobro.
        Carbon::setTestNow($t0->copy()->addSeconds(40));
        $this->cobrar()->assertCreated();

        $this->assertSame(1, Sale::count());

        $medicion = ActivityTiming::sole();

        $this->assertSame(TimedActivity::VENTA, $medicion->activity);
        $this->assertSame(TimedActivity::CANAL_WEB, $medicion->channel);
        $this->assertSame($this->vendedor->id, $medicion->user_id);
        $this->assertSame(40, $medicion->seconds);
        $this->assertFalse($medicion->discarded);
    }

    public function test_la_segunda_venta_se_mide_desde_que_la_pantalla_quedo_libre(): void
    {
        $t0 = Carbon::parse(self::T0);

        Carbon::setTestNow($t0);
        $this->abrirPantallaDeCobro();

        Carbon::setTestNow($t0->copy()->addSeconds(40));
        $this->cobrar()->assertCreated();

        // La pantalla no se recarga entre ventas: la segunda se cobra 25 segundos
        // después. Si el reloj no se reiniciara, esta venta mediría 65.
        Carbon::setTestNow($t0->copy()->addSeconds(65));
        $this->cobrar()->assertCreated();

        $this->assertSame([40, 25], ActivityTiming::orderBy('id')->pluck('seconds')->all());
    }

    public function test_un_mostrador_vacio_mucho_rato_no_cuenta_como_una_venta_lenta(): void
    {
        $t0 = Carbon::parse(self::T0);

        Carbon::setTestNow($t0);
        $this->abrirPantallaDeCobro();

        // La pantalla quedó abierta media hora sin nadie que cobrar.
        Carbon::setTestNow($t0->copy()->addMinutes(30));
        $this->cobrar()->assertCreated();

        $this->assertSame(1, Sale::count(), 'La venta se cobra igual.');
        $this->assertTrue(
            ActivityTiming::sole()->discarded,
            'Media hora de mostrador vacío no es una venta de media hora.'
        );
    }

    public function test_una_venta_sin_reloj_no_inventa_una_medicion(): void
    {
        // Un POST sin haber abierto la pantalla (una integración, una prueba con
        // curl). Sin reloj no hay medición: medio dato es peor que ninguno.
        $this->cobrar()->assertCreated();

        $this->assertSame(1, Sale::count());
        $this->assertSame(0, ActivityTiming::count());
    }

    public function test_si_falla_la_medicion_la_venta_se_cobra_igual(): void
    {
        $t0 = Carbon::parse(self::T0);

        Carbon::setTestNow($t0);
        $this->abrirPantallaDeCobro();

        // La forma más honesta de simular "la tabla de mediciones no responde".
        Schema::drop('activity_timings');

        Carbon::setTestNow($t0->copy()->addSeconds(40));
        $respuesta = $this->cobrar();

        $respuesta->assertCreated();
        $respuesta->assertJsonPath('success', true);
        $this->assertSame(1, Sale::count(), 'La venta se guarda aunque la medición falle.');
        $this->assertSame(49, $this->producto->fresh()->quantity, 'El stock se descontó igual.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function abrirPantallaDeCobro(): void
    {
        $this->actingAs($this->vendedor)->get(route('sales.create'))->assertOk();
    }

    private function cobrar(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->vendedor)->postJson(route('sales.store'), [
            'payment_method' => 'cash',
            'status' => 'completed',
            'cash_received' => 10000,
            'change' => 0,
            'global_discount' => 0,
            'items' => [[
                'product_id' => $this->producto->id,
                'quantity' => 1,
                'unit_price' => 10000,
                'discount' => 0,
            ]],
        ]);
    }
}
