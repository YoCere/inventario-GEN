<?php

namespace App\Support\Ui;

/**
 * Un color y un ícono por módulo, para saber dónde estás sin leer. El color se
 * usa solo para ubicar (ícono del menú y encabezado de la pantalla), nunca para
 * decir si algo salió bien o mal: eso es Tone.
 *
 * Las clases están escritas literales a propósito (ver `content` en tailwind.config.js).
 */
final class Module
{
    /**
     * Módulo de cada ruta, por prefijo del nombre de la ruta. El orden importa:
     * gana el primer prefijo que coincide.
     *
     * @var array<string, string>
     */
    private const ROUTE_PREFIXES = [
        'sales.' => 'ventas',
        'customers.' => 'ventas',
        'shop.admin.' => 'ventas',
        'purchases.' => 'compras',
        'suppliers.' => 'compras',
        'products.' => 'productos',
        'categories.' => 'productos',
        'units.' => 'productos',
        'warehouses.' => 'productos',
        'locations.' => 'productos',
        'transfers.' => 'productos',
        'finance' => 'finanzas',
        'accounting.' => 'finanzas',
        'users.' => 'usuarios',
        'roles.' => 'usuarios',
        'profile.' => 'usuarios',
        'settings.' => 'ajustes',
        'dashboard' => 'inicio',
    ];

    /**
     * @var array<string, array{label: string, icon: string, chip: string, icon_color: string}>
     */
    private const MODULES = [
        'ventas' => [
            'label' => 'Ventas',
            'icon' => 'banknotes',
            'chip' => 'bg-emerald-50 dark:bg-emerald-950/40',
            'icon_color' => 'text-emerald-600 dark:text-emerald-400',
        ],
        'compras' => [
            'label' => 'Compras',
            'icon' => 'shopping-cart',
            'chip' => 'bg-blue-50 dark:bg-blue-950/40',
            'icon_color' => 'text-blue-600 dark:text-blue-400',
        ],
        'productos' => [
            'label' => 'Productos',
            'icon' => 'cube',
            'chip' => 'bg-amber-50 dark:bg-amber-950/40',
            'icon_color' => 'text-amber-600 dark:text-amber-400',
        ],
        'finanzas' => [
            'label' => 'Finanzas',
            'icon' => 'currency-dollar',
            'chip' => 'bg-violet-50 dark:bg-violet-950/40',
            'icon_color' => 'text-violet-600 dark:text-violet-400',
        ],
        'usuarios' => [
            'label' => 'Usuarios',
            'icon' => 'users',
            'chip' => 'bg-pink-50 dark:bg-pink-950/40',
            'icon_color' => 'text-pink-600 dark:text-pink-400',
        ],
        'ajustes' => [
            'label' => 'Ajustes',
            'icon' => 'cog-6-tooth',
            'chip' => 'bg-muted',
            'icon_color' => 'text-muted-foreground',
        ],
        'inicio' => [
            'label' => 'Inicio',
            'icon' => 'squares-2x2',
            'chip' => 'bg-muted',
            'icon_color' => 'text-muted-foreground',
        ],
    ];

    /** Clave del módulo de la ruta actual, o null si la ruta no es de ningún módulo. */
    public static function current(?string $routeName = null): ?string
    {
        $routeName ??= request()->route()?->getName();
        if (! $routeName) {
            return null;
        }

        foreach (self::ROUTE_PREFIXES as $prefix => $module) {
            if (str_starts_with($routeName, $prefix)) {
                return $module;
            }
        }

        return null;
    }

    /**
     * @return array{label: string, icon: string, chip: string, icon_color: string}|null
     */
    public static function get(?string $module): ?array
    {
        return $module === null ? null : (self::MODULES[$module] ?? null);
    }

    /** Color del ícono del módulo, para el menú. */
    public static function iconColor(string $module): string
    {
        return self::MODULES[$module]['icon_color'] ?? 'text-muted-foreground';
    }
}
