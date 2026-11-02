<?php

namespace Tests\Feature\Metrics;

use App\Livewire\Reports\WorkTimings;
use App\Models\ActivityTiming;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\Metrics\TimedActivity;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La pantalla de tiempos: quién la ve y qué número muestra.
 *
 * La mediana manda. Un caso de 40 minutos en medio de cargas de un minuto
 * arruina el promedio y no representa a nadie; la mediana ni se mueve. Esta
 * pantalla existe para que el dueño pueda prometer un número que se cumple.
 */
class PantallaDeTiemposTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function usuarioConRol(string $rol): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole($rol);

        return $user;
    }

    // ── Permisos ─────────────────────────────────────────────────────────────

    public function test_sin_el_permiso_la_pantalla_de_tiempos_no_se_ve(): void
    {
        $cajero = $this->usuarioConRol('staff');

        $this->assertFalse($cajero->can('metrics.view'));
        $this->actingAs($cajero)->get(route('reports.work-timings'))->assertForbidden();
    }

    public function test_sin_el_permiso_la_pantalla_de_tiempos_ni_aparece_en_el_menu(): void
    {
        $this->actingAs($this->usuarioConRol('staff'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Tiempos de carga y venta');
    }

    public function test_con_el_permiso_la_pantalla_se_ve_y_la_ofrece_el_menu(): void
    {
        $duenia = $this->usuarioConRol('admin');

        $this->actingAs($duenia)->get(route('reports.work-timings'))
            ->assertOk()
            ->assertSee('Tiempos de carga y venta');

        $this->actingAs($duenia)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Tiempos de carga y venta');
    }

    public function test_el_emprendedor_tambien_puede_ver_sus_tiempos(): void
    {
        // Es justo quien pidió el número para mostrárselo a un cliente nuevo.
        $this->actingAs($this->usuarioConRol('emprendedor'))
            ->get(route('reports.work-timings'))
            ->assertOk();
    }

    // ── La mediana ───────────────────────────────────────────────────────────

    public function test_la_mediana_no_se_deja_arruinar_por_un_caso_extremo(): void
    {
        // Cinco cargas rápidas y una de 10 minutos metida en el medio.
        foreach ([50, 55, 600, 60, 70] as $segundos) {
            $this->medicion(TimedActivity::ALTA_PRODUCTO, TimedActivity::CANAL_WEB, $segundos);
        }

        // Ordenados: 50, 55, 60, 70, 600 → el del medio es 60.
        // El promedio es 167: un número que no le pasó a nadie.
        $numeros = $this->numerosEnPantalla(TimedActivity::ALTA_PRODUCTO, TimedActivity::CANAL_WEB);

        $this->assertSame(5, $numeros['cantidad']);
        $this->assertSame(60.0, $numeros['mediana']);
        $this->assertSame(167.0, round($numeros['promedio']));
        $this->assertSame('1 min', $numeros['mediana_label']);
    }

    public function test_con_cantidad_par_la_mediana_promedia_los_dos_del_medio(): void
    {
        $this->assertSame(65.0, ActivityTiming::mediana([50, 60, 70, 900]));
        $this->assertSame(60.0, ActivityTiming::mediana([50, 60, 70]));
        $this->assertNull(ActivityTiming::mediana([]), 'Sin mediciones no se inventa un número.');
    }

    public function test_las_cargas_descartadas_no_entran_en_la_mediana_pero_se_informan(): void
    {
        foreach ([50, 60, 70] as $segundos) {
            $this->medicion(TimedActivity::ALTA_PRODUCTO, TimedActivity::CANAL_WEB, $segundos);
        }
        // Un abandono: una hora con el formulario abierto.
        $this->medicion(TimedActivity::ALTA_PRODUCTO, TimedActivity::CANAL_WEB, 3600, descartada: true);

        $numeros = $this->numerosEnPantalla(TimedActivity::ALTA_PRODUCTO, TimedActivity::CANAL_WEB);

        $this->assertSame(3, $numeros['cantidad'], 'El abandono no se cuenta como carga.');
        $this->assertSame(60.0, $numeros['mediana']);
        $this->assertSame(1, $numeros['abandonadas'], 'Pero sí se informa que hubo uno.');
    }

    public function test_cada_canal_tiene_su_propio_numero(): void
    {
        $this->medicion(TimedActivity::ALTA_PRODUCTO, TimedActivity::CANAL_WEB, 120);
        $this->medicion(TimedActivity::ALTA_PRODUCTO, TimedActivity::CANAL_BOT, 60);
        $this->medicion(TimedActivity::ALTA_PRODUCTO, TimedActivity::CANAL_BOT_VOZ, 20);

        $this->assertSame(
            120.0,
            $this->numerosEnPantalla(TimedActivity::ALTA_PRODUCTO, TimedActivity::CANAL_WEB)['mediana']
        );
        $this->assertSame(
            20.0,
            $this->numerosEnPantalla(TimedActivity::ALTA_PRODUCTO, TimedActivity::CANAL_BOT_VOZ)['mediana']
        );
    }

    // ── Tiempo por paso del alta por el bot ──────────────────────────────────

    public function test_la_pantalla_muestra_en_que_paso_del_bot_se_tarda_mas(): void
    {
        $this->medicion(TimedActivity::ALTA_PRODUCTO, TimedActivity::CANAL_BOT, 100, pasos: [
            'nuevo:nombre' => 10,
            'nuevo:categoria' => 50,
            'nuevo:precio_venta' => 40,
        ]);
        $this->medicion(TimedActivity::ALTA_PRODUCTO, TimedActivity::CANAL_BOT, 80, pasos: [
            'nuevo:nombre' => 8,
            'nuevo:categoria' => 60,
            'nuevo:precio_venta' => 12,
        ]);

        $pasos = collect($this->pantalla()->viewData('pasosDelBot'))->keyBy('paso');

        $this->assertSame('Categoría', $pasos['nuevo:categoria']['label']);
        $this->assertSame(55.0, $pasos['nuevo:categoria']['mediana']);
        $this->assertSame(2, $pasos['nuevo:categoria']['veces']);
        // El paso más lento llena la barra; los demás se miden contra él.
        $this->assertSame(100, $pasos['nuevo:categoria']['porcentaje']);
        $this->assertLessThan(100, $pasos['nuevo:nombre']['porcentaje']);

        // El orden es el orden en que la persona vive los pasos, no el del total.
        $this->assertSame(
            ['nuevo:nombre', 'nuevo:categoria', 'nuevo:precio_venta'],
            array_column($this->pantalla()->viewData('pasosDelBot'), 'paso')
        );
    }

    public function test_sin_mediciones_la_pantalla_lo_dice_en_vez_de_mostrar_un_cero(): void
    {
        $this->pantalla()
            ->assertSee('Todavía no hay ninguna medición en este período')
            ->assertSee('Todavía no hay altas hechas por el bot');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @param array<string, int> $pasos */
    private function medicion(
        string $actividad,
        string $canal,
        int $segundos,
        bool $descartada = false,
        array $pasos = [],
    ): ActivityTiming {
        return ActivityTiming::create([
            'activity' => $actividad,
            'channel' => $canal,
            'user_id' => null,
            'seconds' => $segundos,
            'steps' => $pasos === [] ? null : $pasos,
            'started_at' => now()->subSeconds($segundos),
            'finished_at' => now(),
            'business_date' => BusinessTime::todayString(),
            'discarded' => $descartada,
        ]);
    }

    private function pantalla(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs($this->usuarioConRol('admin'))
            ->test(WorkTimings::class)
            ->set('period', 'todo');
    }

    /** @return array<string, mixed> */
    private function numerosEnPantalla(string $actividad, string $canal): array
    {
        $resumen = collect($this->pantalla()->viewData('resumen'))->firstWhere('clave', $actividad);

        return collect($resumen['canales'])->firstWhere('canal', $canal);
    }
}
