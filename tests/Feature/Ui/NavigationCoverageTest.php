<?php

namespace Tests\Feature\Ui;

use App\Support\Ui\Navigation;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Nada queda huérfano: toda pantalla con nombre de ruta se alcanza desde el
 * menú, o está acá abajo con el motivo por el que no hace falta.
 *
 * Si agregás una pantalla nueva y este test se cae, la respuesta correcta casi
 * siempre es ponerla en App\Support\Ui\Navigation, no en las excepciones.
 */
class NavigationCoverageTest extends TestCase
{
    /**
     * Rutas GET con nombre que a propósito NO están en el menú.
     * Patrón fnmatch => motivo.
     *
     * @var array<string, string>
     */
    private const EXCEPTIONS = [
        // --- Infraestructura / paquetes ---
        'manifest' => 'Manifest PWA: lo lee el navegador, no una persona.',
        'storage.local' => 'Servidor de archivos de storage (framework).',
        'livewire.*' => 'Rutas internas de Livewire.',
        'debugbar.*' => 'Debugbar, solo en desarrollo.',
        'sanctum.*' => 'Rutas internas de Sanctum.',
        'horizon*' => 'Panel de colas (paquete).',

        // --- Autenticación (fuera del menú por definición) ---
        'login' => 'Flujo de autenticación: se ve sin sesión.',
        'logout' => 'Acción del menú de la cuenta (POST).',
        'register' => 'Flujo de autenticación.',
        'password.*' => 'Flujo de recuperación de contraseña.',
        'verification.*' => 'Flujo de verificación de correo.',

        // --- Ventanas de impresión ---
        '*.print' => 'Ventana de impresión: se abre desde la pantalla que la genera.',

        // --- Detalle / alta que se abre desde su lista ---
        'purchases.create' => 'Alta desde el botón de la lista de Compras.',
        'purchases.show' => 'Detalle desde la lista de Compras.',
        'purchases.edit' => 'Edición desde el detalle de la compra.',
        'sales.show' => 'Detalle desde la lista de Ventas.',
        'users.payroll.create' => 'Alta desde la Planilla de sueldos.',
        'users.payroll.show' => 'Detalle desde la Planilla de sueldos.',

        // --- Tarjetas del hub Tesorería (Finanzas > Tesorería) ---
        'finance.transactions.index' => 'Tarjeta del hub Tesorería.',
        'finance.categories.index' => 'Tarjeta del hub Tesorería.',

        // --- Tarjetas del hub Contabilidad (Finanzas > Contabilidad) ---
        'finance.chart-of-accounts.index' => 'Tarjeta del hub Contabilidad.',
        'finance.journal-entries.index' => 'Tarjeta del hub Contabilidad.',
        'finance.journal-entries.create' => 'Alta desde el Libro diario.',
        'finance.journal-entries.book' => 'Vista de libro desde el Libro diario.',
        'finance.statements.index' => 'Tarjeta del hub Contabilidad.',
        'finance.accounting-periods.index' => 'Tarjeta del hub Contabilidad.',
        'finance.trial-balance' => 'Tarjeta del hub Contabilidad.',
        'finance.worksheet' => 'Tarjeta del hub Contabilidad.',
        'accounting.opening.index' => 'Tarjeta del hub Contabilidad (solo admin).',
        'accounting.closing.index' => 'Tarjeta del hub Contabilidad (solo admin).',

        // --- Tarjetas del hub Activos y operaciones (Finanzas) ---
        'finance.fixed-assets.index' => 'Tarjeta del hub Activos y operaciones.',
        'finance.fixed-assets.schedule' => 'Detalle desde Activos fijos.',
        'finance.asset-categories.index' => 'Tarjeta del hub Activos y operaciones.',
        'finance.loans.index' => 'Tarjeta del hub Activos y operaciones.',
        'finance.loans.schedule' => 'Detalle desde Préstamos.',
        'finance.budgets.index' => 'Tarjeta del hub Activos y operaciones.',
        'finance.budgets.show' => 'Detalle desde Presupuestos.',
        'finance.boms.index' => 'Tarjeta del hub Activos y operaciones.',
        'finance.production.index' => 'Tarjeta del hub Activos y operaciones.',

        // --- Redirecciones heredadas ---
        'finance.kardex.legacy-redirect' => 'Redirección de una URL vieja.',
        'finance.payroll.legacy-redirect' => 'Redirección de una URL vieja.',

        // --- Tienda pública (la ve el cliente, no el del mostrador) ---
        'shop.index' => 'Tienda pública: se abre desde el editor de la landing.',
        'shop.catalog' => 'Tienda pública.',
        'shop.product' => 'Tienda pública.',
        'shop.checkout' => 'Tienda pública.',
        'shop.search' => 'Tienda pública (JSON).',
    ];

    public function test_ninguna_pantalla_queda_fuera_del_menu(): void
    {
        $inMenu = Navigation::routeNames();
        $orphans = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if (! $name || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (in_array($name, $inMenu, true) || $this->isException($name)) {
                continue;
            }

            $orphans[] = $name;
        }

        sort($orphans);

        $this->assertSame([], $orphans, implode("\n", [
            'Pantallas accesibles solo escribiendo la URL:',
            ...array_map(fn ($n) => "  - {$n}", $orphans),
            'Agregalas a App\Support\Ui\Navigation o justificá la excepción en este test.',
        ]));
    }

    public function test_todo_item_del_menu_apunta_a_una_ruta_que_existe(): void
    {
        $missing = array_values(array_filter(
            Navigation::routeNames(),
            // La tienda solo registra sus rutas con shop_enabled='1'.
            fn (string $name) => ! Route::has($name) && ! str_starts_with($name, 'shop.')
        ));

        $this->assertSame([], $missing, 'Ítems del menú que apuntan a una ruta inexistente: ' . implode(', ', $missing));
    }

    public function test_cada_excepcion_lleva_su_motivo(): void
    {
        foreach (self::EXCEPTIONS as $pattern => $reason) {
            $this->assertNotSame('', trim($reason), "La excepción '{$pattern}' no explica por qué.");
        }
    }

    private function isException(string $name): bool
    {
        foreach (array_keys(self::EXCEPTIONS) as $pattern) {
            if (fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }
}
