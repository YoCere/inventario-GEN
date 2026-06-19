<?php

namespace App\Services\Assistant;

use App\Models\User;

/**
 * Construye el system-prompt curado del asistente web: identidad, mapa de módulos
 * (filtrado por lo que el usuario puede ver) y la ruta actual para ayuda contextual.
 */
class WebAssistantPrompt
{
    /** @var array<int, array{0: ?string, 1: string}> */
    private const MODULES = [
        [null,            'Inicio/Dashboard: resumen del negocio en la pantalla principal.'],
        ['sales.view',    'Ventas: registrar y consultar ventas (menú Ventas).'],
        ['products.view', 'Productos: catálogo, stock, categorías y unidades (menú Productos).'],
        ['products.view', 'Inventario: stock por ubicación, transferencias y kardex.'],
        ['purchases.view','Compras: registrar compras a proveedores (menú Compras).'],
        ['customers.manage','Clientes: gestión de clientes (menú Maestros > Clientes).'],
        ['finance.view',  'Finanzas: resumen financiero, transacciones e informes.'],
        ['finance.accounting','Contabilidad: plan de cuentas, libro diario y estados financieros.'],
        ['users.manage',  'Usuarios: gestión de usuarios del sistema (menú Usuarios).'],
        ['settings.view', 'Ajustes: configuración del negocio (menú Ajustes).'],
    ];

    public static function build(User $user, string $route): string
    {
        $lines = [];
        foreach (self::MODULES as [$perm, $text]) {
            if ($perm === null || $user->can($perm)) {
                $lines[] = '- ' . $text;
            }
        }
        $modules = implode("\n", $lines);

        return <<<PROMPT
        Eres el asistente interno de un sistema POS e inventario en español (Bolivia).
        Ayudas al usuario a USAR el sistema y a entender su negocio con datos reales.

        Reglas:
        - Responde corto, claro y en español. Sé concreto.
        - Para datos del negocio (ventas, stock, finanzas) USA las herramientas disponibles;
          no inventes cifras. Si no tienes una herramienta para algo, dilo.
        - Solo menciona módulos y funciones que el usuario puede ver (listados abajo).
        - No ejecutas acciones que modifiquen datos; solo informas y orientas.

        Módulos disponibles para este usuario:
        {$modules}

        El usuario está ahora en la página/ruta: "{$route}". Da ayuda contextual a esa pantalla cuando aplique.
        PROMPT;
    }
}
