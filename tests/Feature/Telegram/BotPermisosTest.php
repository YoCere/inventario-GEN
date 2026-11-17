<?php

namespace Tests\Feature\Telegram;

use App\Models\Sale;
use App\Models\Setting;
use App\Models\TelegramConversation;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Messaging\TelegramService;
use App\Services\Telegram\BotHandler;
use App\Services\Telegram\PermisosDelBot;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Lo que cada empleado puede y no puede hacer por Telegram.
 *
 * El agujero que estos tests no dejan volver: el bot solo pedía estar vinculado.
 * Un `staff` —que en el sistema web solo ve productos y cobra— podía crear
 * productos y anular la venta de cualquier compañero, porque los 19 comandos del
 * bot no consultaban el modelo de permisos que el resto del sistema sí respeta.
 *
 * El recorrido de la tabla es a propósito: un comando nuevo que se agregue sin
 * decidir su permiso rompe este test solo, sin que nadie se tenga que acordar.
 */
class BotPermisosTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '770001';

    /** Textos que mandó el bot en el mensaje que se está mirando. */
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
        $telegram->shouldReceive('answerCallbackQuery')->andReturn([]);
        $telegram->shouldReceive('editMessageReplyMarkup')->andReturn([]);
        $telegram->shouldReceive('sendChatAction')->andReturn([]);
        $telegram->shouldReceive('deleteMessage')->andReturn([]);
        $this->app->instance(TelegramService::class, $telegram);

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // ── La tabla comando → permiso, recorrida entera ─────────────────────────

    public function test_con_permisos_estrictos_el_staff_solo_pasa_los_comandos_que_le_tocan(): void
    {
        Setting::set(PermisosDelBot::AJUSTE_ESTRICTO, '1');
        $staff = $this->vincula('staff');

        $rechazados = 0;

        foreach ($this->comandosDeLaTabla() as $comando => $permiso) {
            $this->enviados = [];
            $this->texto($comando);
            $dicho = $this->todoLoDicho();

            if ($staff->can($permiso)) {
                $this->assertStringNotContainsString(
                    'necesitás permiso',
                    $dicho,
                    "{$comando} exige {$permiso}, que el staff SÍ tiene: no se puede frenar."
                );
                continue;
            }

            $rechazados++;
            $this->assertStringContainsString(
                'necesitás permiso',
                $dicho,
                "{$comando} exige {$permiso}, que el staff no tiene: tiene que frenarse."
            );
            $this->assertStringNotContainsString(
                $permiso,
                $dicho,
                "El empleado no tiene por qué leer '{$permiso}': el aviso se dice con palabras."
            );
        }

        $this->assertGreaterThanOrEqual(
            3,
            $rechazados,
            'Si no se frenó nada, el recorrido de la tabla no está probando nada.'
        );
    }

    public function test_con_permisos_estrictos_el_dueno_pasa_todos_los_comandos(): void
    {
        Setting::set(PermisosDelBot::AJUSTE_ESTRICTO, '1');
        $this->vincula('developer');

        foreach ($this->comandosDeLaTabla() as $comando => $permiso) {
            $this->enviados = [];
            $this->texto($comando);

            $this->assertStringNotContainsString(
                'necesitás permiso',
                $this->todoLoDicho(),
                "Al dueño no se le puede frenar {$comando}."
            );
        }
    }

    public function test_el_emprendedor_carga_productos_y_anula_ventas_sin_que_lo_frenen(): void
    {
        // El rol del dueño de un negocio chico. No tiene contabilidad a propósito
        // (/reportes sigue siendo de finanzas), pero la operación diaria es suya.
        Setting::set(PermisosDelBot::AJUSTE_ESTRICTO, '1');
        $this->vincula('emprendedor');

        foreach (['/nuevo', '/devolver', '/deshacer', '/listar', '/stock', '/ventas'] as $comando) {
            $this->enviados = [];
            $this->texto($comando);

            $this->assertStringNotContainsString(
                'necesitás permiso',
                $this->todoLoDicho(),
                "El emprendedor es el dueño: {$comando} es su trabajo."
            );
        }
    }

    public function test_los_comandos_personales_y_de_sesion_no_exigen_nada(): void
    {
        Setting::set(PermisosDelBot::AJUSTE_ESTRICTO, '1');

        // Usuario sin ningún rol: el piso absoluto. Igual tiene que poder pedir
        // ayuda, anotarse un recordatorio y cerrar su sesión.
        $this->vincula(null);

        foreach (['/ayuda', '/recordar', '/cancelar'] as $comando) {
            $this->enviados = [];
            $this->texto($comando);

            $this->assertStringNotContainsString('necesitás permiso', $this->todoLoDicho(), $comando);
        }
    }

    // ── Botones: la misma puerta, no una de atrás ────────────────────────────

    public function test_con_permisos_estrictos_el_staff_no_puede_dar_de_alta_un_producto_a_botonazos(): void
    {
        Setting::set(PermisosDelBot::AJUSTE_ESTRICTO, '1');
        $this->vincula('staff');

        $this->toqueDeBoton('prod:guardar');

        $this->assertStringContainsString('necesitás permiso', $this->todoLoDicho());
    }

    public function test_al_dueno_los_botones_del_alta_le_responden(): void
    {
        Setting::set(PermisosDelBot::AJUSTE_ESTRICTO, '1');
        $this->vincula('developer');

        $this->toqueDeBoton('prod:guardar');

        $this->assertStringNotContainsString('necesitás permiso', $this->todoLoDicho());
    }

    // ── Venta directa por lenguaje natural ───────────────────────────────────

    public function test_con_permisos_estrictos_vender_escribiendo_exige_poder_vender(): void
    {
        Setting::set(PermisosDelBot::AJUSTE_ESTRICTO, '1');

        // Sin rol: ni sales.create. Antes esta frase registraba una venta igual.
        $this->vincula(null);

        $this->texto('vende 2 mates a 20');

        $this->assertStringContainsString('necesitás permiso', $this->todoLoDicho());
        $this->assertSame(0, Sale::count(), 'Sin permiso de vender no se escribe ninguna venta.');
    }

    public function test_el_staff_puede_vender_escribiendo_porque_para_eso_esta(): void
    {
        Setting::set(PermisosDelBot::AJUSTE_ESTRICTO, '1');
        $this->vincula('staff');

        $this->texto('vende 2 mates a 20');

        $this->assertStringNotContainsString(
            'necesitás permiso',
            $this->todoLoDicho(),
            'El staff cobra: frenarle la venta sería romperle el trabajo.'
        );
    }

    // ── Modo registro: el ajuste apagado no bloquea, pero deja constancia ────

    public function test_sin_el_ajuste_el_staff_pasa_pero_queda_el_aviso_en_la_bitacora(): void
    {
        $this->assertSame(
            '0',
            Setting::get(PermisosDelBot::AJUSTE_ESTRICTO, '0'),
            'El ajuste arranca apagado: hay empleados reales cargando productos por el bot.'
        );

        $staff = $this->vincula('staff');

        Log::spy();

        $this->texto('/nuevo');

        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto = []) use ($staff) {
            return $mensaje === 'Bot: permiso faltante'
                && ($contexto['comando'] ?? null) === '/nuevo'
                && ($contexto['permiso'] ?? null) === 'products.manage'
                && (int) ($contexto['user_id'] ?? 0) === $staff->id
                && (string) ($contexto['chat_id'] ?? '') === self::CHAT;
        })->once();

        $this->assertStringNotContainsString(
            'necesitás permiso',
            $this->todoLoDicho(),
            'En modo registro no se bloquea: se anota y se deja trabajar.'
        );
        $this->assertSame(
            'nuevo:nombre',
            TelegramConversation::where('chat_id', self::CHAT)->value('step'),
            'El alta tiene que haber arrancado igual.'
        );
    }

    // ── /devolver: nunca la venta de otro, con ajuste o sin él ───────────────

    public function test_devolver_no_le_muestra_al_staff_la_venta_de_otro_vendedor(): void
    {
        // A propósito en modo registro: el filtro de ventas propias NO depende
        // del ajuste. Que un vendedor anule la venta de otro es un agujero, no
        // una comodidad que se pueda dejar prendida un día más.
        $this->vincula('staff');

        $ajena = $this->ventaDeHoyDe($this->usuarioCon('staff'));

        $this->texto('/devolver');

        $this->assertStringNotContainsString(
            $ajena->invoice_number,
            $this->todoLoDicho(),
            'La venta de un compañero no se le muestra ni para mirarla.'
        );
        $this->assertStringContainsString('No hay ventas tuyas', $this->todoLoDicho());
    }

    public function test_devolver_si_le_muestra_al_staff_su_propia_venta(): void
    {
        $staff = $this->vincula('staff');
        $propia = $this->ventaDeHoyDe($staff);

        $this->texto('/devolver');

        $this->assertStringContainsString(
            $propia->invoice_number,
            $this->todoLoDicho(),
            'Lo que uno vendió lo tiene que poder devolver.'
        );
    }

    public function test_devolver_le_muestra_al_admin_las_ventas_de_todos(): void
    {
        $this->vincula('admin');

        $ajena = $this->ventaDeHoyDe($this->usuarioCon('staff'));

        $this->texto('/devolver');

        $this->assertStringContainsString(
            $ajena->invoice_number,
            $this->todoLoDicho(),
            'El admin tiene que ver el día completo para poder arreglar un error ajeno.'
        );
    }

    /**
     * El caso que de verdad importa para el producto: el sistema se vende como
     * una instancia por negocio, y ahí el dueño tiene rol `emprendedor` mientras
     * el empleado tiene `staff`.
     *
     * Con el criterio inicial —`isAdmin()`, o sea `admin` o `developer`— el dueño
     * no podía anular por Telegram la venta que su empleado cargó mal. Endurecer
     * no puede significar dejar al dueño sin poder arreglar su propio negocio.
     */
    public function test_devolver_le_muestra_al_emprendedor_las_ventas_de_su_empleado(): void
    {
        $this->vincula('emprendedor');

        $ajena = $this->ventaDeHoyDe($this->usuarioCon('staff'));

        $this->texto('/devolver');

        $this->assertStringContainsString(
            $ajena->invoice_number,
            $this->todoLoDicho(),
            'El dueño de la instancia tiene que poder corregir el error de su empleado.'
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Comandos de la tabla que exigen permiso. Quedan afuera los prefijos de
     * botón (no se escriben) y la venta directa (no es un comando).
     *
     * @return array<string, string>
     */
    private function comandosDeLaTabla(): array
    {
        $comandos = [];

        foreach (PermisosDelBot::TABLA as $accion => $permiso) {
            if ($permiso === null || str_ends_with($accion, ':') || ! str_starts_with($accion, '/')) {
                continue;
            }
            $comandos[$accion] = $permiso;
        }

        return $comandos;
    }

    private function usuarioCon(?string $rol): User
    {
        $user = User::factory()->create();

        if ($rol !== null) {
            $user->assignRole($rol);
        }

        return $user;
    }

    /** Crea el usuario con ese rol y lo deja vinculado al chat de la prueba. */
    private function vincula(?string $rol): User
    {
        $user = $this->usuarioCon($rol);

        TelegramUser::create([
            'chat_id' => self::CHAT,
            'user_id' => $user->id,
            'identifier' => $user->email,
            'last_login' => now(),
        ]);

        return $user;
    }

    private function ventaDeHoyDe(User $vendedor): Sale
    {
        return Sale::factory()->create([
            'created_by' => $vendedor->id,
            'status' => 'completed',
            'created_at' => now(),
            'total' => 5000,
        ]);
    }

    private function texto(string $texto): void
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

    private function todoLoDicho(): string
    {
        return implode("\n", $this->enviados);
    }
}
