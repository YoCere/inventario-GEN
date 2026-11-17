<?php

namespace App\Services\Telegram;

use App\Models\Setting;
use App\Models\User;
use App\Services\Messaging\TelegramService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Log;

/**
 * Qué permiso hace falta para cada cosa que se puede pedir por el bot.
 *
 * El bot nació pidiendo una sola cosa: estar vinculado. Alcanzaba cuando lo
 * usaba una persona. Hoy lo usan empleados, y un `staff` —que en el sistema web
 * solo puede ver productos y cobrar— podía por Telegram crear productos y
 * anular la venta de cualquier compañero. El camino de la IA sí respeta los
 * permisos (ToolRegistry los filtra con `$user->can()`); el camino determinista,
 * que es el que más se usa, no consultaba nada.
 *
 * Acá no se inventa un segundo modelo de permisos: se pregunta al mismo
 * `$user->can()` de siempre, que respeta el `Gate::before` que le da todo al
 * developer. Lo único que vive en esta clase es la tabla de qué comando
 * necesita qué permiso, en un solo lugar, para que agregar un comando nuevo sea
 * agregar una fila y no acordarse de copiar un `if`.
 *
 * MODO REGISTRO: el ajuste `bot_permisos_estrictos` arranca en '0' a propósito.
 * Hay empleados reales que hoy cargan productos por el bot sin tener
 * `products.manage`; bloquear de entrada les rompe el trabajo del día. En '0' la
 * compuerta solo deja constancia en el log de lo que habría bloqueado, para que
 * el dueño mire un día de uso real y recién entonces lo ponga en '1'.
 */
class PermisosDelBot
{
    /** Ajuste que decide si la compuerta bloquea o solo deja constancia. */
    public const AJUSTE_ESTRICTO = 'bot_permisos_estrictos';

    /** Acción de la tabla que representa vender escribiendo ("vende 2 mates a 20"). */
    public const VENTA_DIRECTA = 'venta directa';

    /**
     * Comando (o prefijo de botón, los que terminan en ':') → permiso que exige.
     *
     * `null` quiere decir "cualquiera que esté vinculado": son cosas personales
     * (recordatorios), de sesión (entrar, salir, cambiar de usuario) o de ayuda,
     * donde pedir un permiso sería un trámite sin nada que proteger.
     *
     * Los que no exigen nada están listados igual, en vez de quedar fuera por
     * omisión: así se ve de un vistazo que la decisión fue tomada y no olvidada.
     *
     * @var array<string, string|null>
     */
    public const TABLA = [
        // Alta de producto: el comando y los botones del mismo flujo.
        '/nuevo'               => 'products.manage',
        'prod:'                => 'products.manage',

        // Anular una venta ajena es lo más caro que se puede hacer desde el chat.
        '/devolver'            => 'sales.cancel',

        // Vender, y deshacer lo que uno vendió, son la misma facultad.
        self::VENTA_DIRECTA    => 'sales.create',
        '/deshacer'            => 'sales.create',

        // Consultas del catálogo.
        '/listar'              => 'products.view',
        '/stock'               => 'products.view',

        // Cifras de ventas del día.
        '/ventas'              => 'sales.view',

        // Reportes del negocio: gerencia, no operación diaria.
        '/reportes'            => 'finance.view',

        // Sin permiso: personales, de sesión o de ayuda.
        '/recordar'            => null,
        '/recordatorios'       => null,
        '/ayuda'               => null,
        '/help'                => null,
        '/start'               => null,
        '/cancelar'            => null,
        '/salir'               => null,
        '/logout'              => null,
        '/cambiar'             => null,
        '/cambiarusuario'      => null,
        '/activar'             => null,
        '/detener'             => null,
    ];

    public function __construct(
        protected TelegramService $telegram,
    ) {}

    /** Permiso que exige una acción, o null si no exige ninguno. */
    public static function permisoDe(string $accion): ?string
    {
        return self::TABLA[mb_strtolower(trim($accion))] ?? null;
    }

    /**
     * Permiso que exige el dato de un botón ("prod:guardar", "buscar:vender").
     * Los botones no tienen un nombre propio en la tabla: se los reconoce por el
     * tramo de flujo con el que arranca su dato.
     */
    public static function permisoDeBoton(string $payload): ?string
    {
        foreach (self::TABLA as $clave => $permiso) {
            if (str_ends_with($clave, ':') && str_starts_with($payload, $clave)) {
                return $permiso;
            }
        }

        return null;
    }

    /** Con el ajuste en '1' la compuerta bloquea; en cualquier otro valor solo registra. */
    public static function esEstricto(): bool
    {
        return Setting::get(self::AJUSTE_ESTRICTO, '0') === '1';
    }

    /**
     * ¿Se puede seguir con esta acción? Devuelve false solo cuando hay que
     * cortar; en modo registro devuelve true aunque falte el permiso.
     *
     * El aviso al usuario se manda desde acá para que cada punto de llamada sea
     * una línea y nadie se olvide de contestarle a la persona.
     */
    public function permite(string $accion, ?User $usuario, string $chatId): bool
    {
        return $this->decidir($accion, self::permisoDe($accion), $usuario, $chatId);
    }

    /** Igual que permite(), pero la acción llega como el dato de un botón. */
    public function permiteBoton(string $payload, ?User $usuario, string $chatId): bool
    {
        return $this->decidir($payload, self::permisoDeBoton($payload), $usuario, $chatId);
    }

    private function decidir(string $accion, ?string $permiso, ?User $usuario, string $chatId): bool
    {
        if ($permiso === null) {
            return true;
        }

        if ($usuario && $usuario->can($permiso)) {
            return true;
        }

        Log::warning('Bot: permiso faltante', [
            'comando' => $accion,
            'permiso' => $permiso,
            'user_id' => $usuario?->id,
            'chat_id' => $chatId,
            'estricto' => self::esEstricto(),
        ]);

        if (! self::esEstricto()) {
            // Modo registro: se deja constancia y se deja trabajar.
            return true;
        }

        $this->telegram->sendMessage($chatId, $this->mensajeDeRechazo($permiso));

        return false;
    }

    /**
     * Lo que ve la persona. Nunca un error técnico ni el nombre interno del
     * permiso a secas: el empleado no tiene por qué saber qué es "sales.cancel",
     * y lo único que necesita es saber a quién pedírselo.
     */
    private function mensajeDeRechazo(string $permiso): string
    {
        $queEs = RolesAndPermissionsSeeder::PERMISSIONS[$permiso] ?? $permiso;

        return "🔒 Para eso necesitás permiso de <b>" . mb_strtolower($queEs) . "</b>.\n\n" .
            "Pedíselo al dueño del sistema.";
    }

    /**
     * ¿Puede ver y anular ventas que no son suyas?
     *
     * Esto NO depende del ajuste de modo registro: que un vendedor anule la
     * venta de otro no es una comodidad que se pueda dejar prendida un día más,
     * es un agujero.
     *
     * POR QUÉ `emprendedor` cuenta, y no solo `isAdmin()`: el sistema se vende
     * como una instancia por negocio, y en esas instalaciones el dueño tiene rol
     * `emprendedor` mientras el empleado tiene `staff`. Con el criterio de
     * `isAdmin()` —que es `admin` o `developer`— el dueño no podía corregir por
     * Telegram la venta que su empleado cargó mal. Eso no es endurecer, es
     * romperle el negocio a quien paga el sistema.
     *
     * Queda como una lista de roles y no como un permiso nuevo porque la
     * pregunta es "¿sos el dueño de este negocio?", que es exactamente lo que
     * dice el rol. Si algún día hace falta un encargado que anule sin ser dueño,
     * ahí sí corresponde un permiso propio (`sales.cancel.any`) y esta lista se
     * reemplaza por él.
     *
     * Nota de consistencia: `QuickSaleService::void()` sigue usando `isAdmin()`
     * para la misma pregunta. Ahí casi no se nota, porque deshace la última
     * venta —que normalmente es propia—, pero son dos criterios para una sola
     * pregunta y conviene unificarlos cuando se toque ese servicio.
     */
    public const ROLES_DUENIO = ['developer', 'admin', 'emprendedor'];

    public static function alcanzaVentasAjenas(?User $usuario): bool
    {
        return (bool) $usuario?->hasAnyRole(self::ROLES_DUENIO);
    }
}
