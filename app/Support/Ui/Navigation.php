<?php

namespace App\Support\Ui;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\User;
use App\Shop\Services\ShopFeatureFlag;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * Fuente única del menú: qué se ve, dónde lleva, quién lo ve y de qué módulo es.
 *
 * Escritorio y móvil renderizan de acá (resources/views/layouts/navigation/*),
 * para que no vuelvan a divergir. El color y el ícono de cada sección salen de
 * App\Support\Ui\Module: acá no se inventan colores.
 *
 * Orden = frecuencia de uso: lo diario primero, lo que se configura una vez
 * agrupado abajo (grupos con 'label').
 *
 * Esta clase no sabe si el menú es superior o lateral: devuelve el árbol ya
 * resuelto (url, activo, contadores). Si algún día se decide pasar a menú
 * lateral fijo, alcanza con una tercera vista que recorra mainFor() igual que
 * layouts/navigation/mobile.blade.php; no hay que tocar permisos ni rutas.
 *
 * Forma de un ítem:
 *  - label    texto visible (lenguaje de negocio, sin emojis)
 *  - route    nombre de la ruta
 *  - icon     heroicon outline sin el prefijo 'heroicon-o-'
 *  - can      permisos Spatie; alcanza con UNO (canAny)
 *  - role     'admin' | 'developer' (además de los permisos)
 *  - feature  'shop' = solo si la tienda está publicada
 *  - match    patrones de routeIs() que marcan el ítem como activo
 *  - badge    clave de contador (ver badge())
 */
final class Navigation
{
    /**
     * Secciones del menú principal, en orden de frecuencia de uso.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function definition(): array
    {
        return [
            [
                'key' => 'inicio',
                'label' => 'Inicio',
                'module' => 'inicio',
                'route' => 'dashboard',
                'can' => ['dashboard.view'],
                'match' => ['dashboard'],
                'groups' => [],
            ],

            [
                'key' => 'ventas',
                'label' => 'Ventas',
                'module' => 'ventas',
                'match' => ['sales.*', 'customers.*', 'shop.admin.*'],
                'groups' => [
                    ['label' => null, 'items' => [
                        [
                            'label' => 'Vender',
                            'route' => 'sales.create',
                            'icon' => 'plus-circle',
                            'can' => ['sales.create'],
                            'match' => ['sales.create'],
                        ],
                        [
                            'label' => 'Ventas',
                            'route' => 'sales.index',
                            'icon' => 'receipt-percent',
                            'can' => ['sales.view'],
                            'match' => ['sales.index', 'sales.show', 'sales.print'],
                        ],
                        [
                            'label' => 'Clientes',
                            'route' => 'customers.index',
                            'icon' => 'user-group',
                            'can' => ['customers.manage'],
                            'match' => ['customers.*'],
                        ],
                    ]],
                    ['label' => 'Tienda en línea', 'items' => [
                        [
                            'label' => 'Reservas web',
                            'route' => 'shop.admin.reservations',
                            'icon' => 'globe-alt',
                            'can' => ['shop.admin'],
                            'feature' => 'shop',
                            'match' => ['shop.admin.*'],
                            'badge' => 'web-reservations',
                        ],
                    ]],
                ],
            ],

            [
                'key' => 'compras',
                'label' => 'Compras',
                'module' => 'compras',
                'match' => ['purchases.*', 'suppliers.*'],
                'groups' => [
                    ['label' => null, 'items' => [
                        [
                            'label' => 'Compras',
                            'route' => 'purchases.index',
                            'icon' => 'shopping-cart',
                            'can' => ['purchases.view'],
                            'match' => ['purchases.*'],
                        ],
                        [
                            'label' => 'Proveedores',
                            'route' => 'suppliers.index',
                            'icon' => 'truck',
                            'can' => ['suppliers.manage'],
                            'match' => ['suppliers.*'],
                        ],
                    ]],
                ],
            ],

            [
                'key' => 'productos',
                'label' => 'Productos',
                'module' => 'productos',
                'match' => [
                    'products.*', 'categories.*', 'units.*',
                    'warehouses.*', 'locations.*', 'transfers.*', 'reports.*',
                ],
                'groups' => [
                    // Día a día.
                    ['label' => null, 'items' => [
                        [
                            'label' => 'Productos',
                            'route' => 'products.index',
                            'icon' => 'cube',
                            'can' => ['products.view'],
                            'match' => ['products.index'],
                        ],
                        [
                            'label' => 'Informe de productos',
                            'route' => 'products.report',
                            'icon' => 'chart-bar',
                            'can' => ['products.view'],
                            'match' => ['products.report', 'products.report.print'],
                        ],
                        [
                            'label' => 'Kardex valorizado',
                            'route' => 'products.kardex.index',
                            'icon' => 'archive-box',
                            'can' => ['products.kardex'],
                            'match' => ['products.kardex.*'],
                        ],
                        [
                            'label' => 'Transferencias',
                            'route' => 'transfers.index',
                            'icon' => 'arrows-right-left',
                            'can' => ['transfers.manage'],
                            'match' => ['transfers.*'],
                        ],
                        [
                            'label' => 'Stock por ubicación',
                            'route' => 'reports.stock-by-location',
                            'icon' => 'map-pin',
                            'can' => ['warehouses.manage'],
                            'match' => ['reports.stock-by-location'],
                        ],
                    ]],
                    // Se configura una vez y casi no se vuelve a tocar.
                    ['label' => 'Datos maestros', 'items' => [
                        [
                            'label' => 'Categorías',
                            'route' => 'categories.index',
                            'icon' => 'tag',
                            'can' => ['categories.manage'],
                            'match' => ['categories.*'],
                        ],
                        [
                            'label' => 'Unidades',
                            'route' => 'units.index',
                            'icon' => 'scale',
                            'can' => ['units.manage'],
                            'match' => ['units.*'],
                        ],
                        [
                            'label' => 'Almacenes',
                            'route' => 'warehouses.index',
                            'icon' => 'building-office-2',
                            'can' => ['warehouses.manage'],
                            'match' => ['warehouses.*'],
                        ],
                        [
                            'label' => 'Ubicaciones',
                            'route' => 'locations.index',
                            'icon' => 'map',
                            'can' => ['warehouses.manage'],
                            'match' => ['locations.*'],
                        ],
                    ]],
                ],
            ],

            [
                'key' => 'finanzas',
                'label' => 'Finanzas',
                'module' => 'finanzas',
                'match' => ['finance.*', 'accounting.*'],
                'groups' => [
                    ['label' => null, 'items' => [
                        [
                            'label' => 'Resumen financiero',
                            'route' => 'finance.index',
                            'icon' => 'chart-pie',
                            'can' => ['finance.view'],
                            'match' => ['finance.index'],
                        ],
                        [
                            'label' => 'Tesorería',
                            'route' => 'finance.hub.treasury',
                            'icon' => 'banknotes',
                            'can' => ['finance.view'],
                            'match' => [
                                'finance.hub.treasury',
                                'finance.transactions.*',
                                'finance.categories.*',
                            ],
                        ],
                        [
                            'label' => 'Contabilidad',
                            'route' => 'finance.hub.accounting',
                            'icon' => 'book-open',
                            'can' => ['finance.accounting', 'products.kardex'],
                            'match' => [
                                'finance.hub.accounting',
                                'finance.chart-of-accounts.*',
                                'finance.journal-entries.*',
                                'finance.statements.*',
                                'finance.accounting-periods.*',
                                'finance.trial-balance',
                                'finance.worksheet',
                                'accounting.*',
                            ],
                        ],
                        [
                            'label' => 'Activos y operaciones',
                            'route' => 'finance.hub.modules',
                            'icon' => 'squares-plus',
                            'can' => [
                                'assets.manage', 'loans.manage', 'budgets.manage',
                                'production.manage', 'users.payroll',
                            ],
                            'match' => [
                                'finance.hub.modules',
                                'finance.fixed-assets.*',
                                'finance.asset-categories.*',
                                'finance.loans.*',
                                'finance.budgets.*',
                                'finance.boms.*',
                                'finance.production.*',
                            ],
                        ],
                    ]],
                ],
            ],

            [
                'key' => 'usuarios',
                'label' => 'Usuarios',
                'module' => 'usuarios',
                'role' => 'admin',
                'match' => ['users.*', 'roles.*'],
                'groups' => [
                    ['label' => null, 'items' => [
                        [
                            'label' => 'Usuarios',
                            'route' => 'users.index',
                            'icon' => 'users',
                            'role' => 'admin',
                            'match' => ['users.index'],
                        ],
                        [
                            'label' => 'Planilla de sueldos',
                            'route' => 'users.payroll.index',
                            'icon' => 'clipboard-document-list',
                            'role' => 'admin',
                            'match' => ['users.payroll.*'],
                        ],
                    ]],
                    // Roles y permisos vive acá (es sobre quién puede qué), no
                    // escondido bajo el avatar. Solo el desarrollador.
                    ['label' => 'Solo desarrollador', 'items' => [
                        [
                            'label' => 'Roles y permisos',
                            'route' => 'roles.index',
                            'icon' => 'shield-check',
                            'role' => 'developer',
                            'match' => ['roles.*'],
                        ],
                    ]],
                ],
            ],
        ];
    }

    /**
     * Ajustes: botón propio (engranaje) arriba a la derecha, no enterrado en el
     * menú del avatar.
     *
     * @return array<string, mixed>
     */
    public static function settingsDefinition(): array
    {
        return [
            'key' => 'ajustes',
            'label' => 'Ajustes',
            'module' => 'ajustes',
            'match' => ['settings.*'],
            'groups' => [
                ['label' => null, 'items' => [
                    [
                        'label' => 'Ajustes del sistema',
                        'route' => 'settings.index',
                        'icon' => 'cog-6-tooth',
                        'can' => ['settings.view'],
                        'match' => ['settings.index'],
                    ],
                    [
                        'label' => 'Página de la tienda',
                        'route' => 'settings.shop-landing',
                        'icon' => 'globe-alt',
                        'can' => ['shop.landing.manage'],
                        'match' => ['settings.shop-landing'],
                    ],
                ]],
                ['label' => 'Solo desarrollador', 'items' => [
                    [
                        'label' => 'Copias de seguridad',
                        'route' => 'settings.backups',
                        'icon' => 'circle-stack',
                        'role' => 'developer',
                        'match' => ['settings.backups'],
                    ],
                ]],
            ],
        ];
    }

    /**
     * Menú del avatar: solo lo de la cuenta. Salir se renderiza aparte porque
     * es un formulario POST.
     *
     * @return array<string, mixed>
     */
    public static function accountDefinition(): array
    {
        return [
            'key' => 'cuenta',
            'label' => 'Mi cuenta',
            'module' => 'usuarios',
            'match' => ['profile.*'],
            'groups' => [
                ['label' => null, 'items' => [
                    [
                        'label' => 'Perfil',
                        'route' => 'profile.index',
                        'icon' => 'user-circle',
                        'match' => ['profile.*'],
                    ],
                ]],
            ],
        ];
    }

    /**
     * Secciones del menú principal que este usuario puede ver, ya resueltas
     * (url, activo, contadores). Las secciones y los grupos que quedan vacíos
     * desaparecen.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function mainFor(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $sections = [];
        foreach (self::definition() as $section) {
            $resolved = self::resolveSection($section, $user);
            if ($resolved !== null) {
                $sections[] = $resolved;
            }
        }

        return $sections;
    }

    /** @return array<string, mixed>|null */
    public static function settingsFor(?User $user): ?array
    {
        return $user ? self::resolveSection(self::settingsDefinition(), $user) : null;
    }

    /** @return array<string, mixed>|null */
    public static function accountFor(?User $user): ?array
    {
        return $user ? self::resolveSection(self::accountDefinition(), $user) : null;
    }

    /**
     * Toda ruta que el menú conoce, visible o no. La usa el test que detecta
     * pantallas huérfanas.
     *
     * @return array<int, string>
     */
    public static function routeNames(): array
    {
        $names = [];

        $all = self::definition();
        $all[] = self::settingsDefinition();
        $all[] = self::accountDefinition();

        foreach ($all as $section) {
            if (isset($section['route'])) {
                $names[] = $section['route'];
            }
            foreach ($section['groups'] ?? [] as $group) {
                foreach ($group['items'] as $item) {
                    $names[] = $item['route'];
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>|null
     */
    private static function resolveSection(array $section, User $user): ?array
    {
        if (! self::allows($section, $user)) {
            return null;
        }

        $module = Module::get($section['module']) ?? [];
        $base = [
            'key' => $section['key'],
            'label' => $section['label'],
            'module' => $section['module'],
            'icon' => $module['icon'] ?? 'squares-2x2',
            'icon_color' => Module::iconColor($section['module']),
            'chip' => $module['chip'] ?? 'bg-muted',
            'active' => self::matches($section['match'] ?? []),
        ];

        // Sección-enlace (Inicio): sin desplegable.
        if (isset($section['route'])) {
            if (! Route::has($section['route'])) {
                return null;
            }

            return $base + [
                'url' => route($section['route']),
                'route' => $section['route'],
                'groups' => [],
                'badge' => null,
            ];
        }

        $groups = [];
        foreach ($section['groups'] as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                if (! self::allows($item, $user) || ! Route::has($item['route'])) {
                    continue;
                }
                $items[] = self::resolveItem($item);
            }
            if ($items !== []) {
                $groups[] = ['label' => $group['label'] ?? null, 'items' => $items];
            }
        }

        if ($groups === []) {
            return null;
        }

        // Una sección también se marca activa si lo está alguno de sus ítems
        // (cubre rutas que no entran en los patrones de la sección).
        $active = $base['active'];
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $active = $active || $item['active'];
            }
        }

        return array_merge($base, ['active' => $active, 'groups' => $groups, 'url' => null, 'route' => null]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function resolveItem(array $item): array
    {
        return [
            'label' => $item['label'],
            'route' => $item['route'],
            'url' => route($item['route']),
            'icon' => $item['icon'] ?? null,
            'active' => self::matches($item['match'] ?? [$item['route']]),
            'badge' => isset($item['badge']) ? self::badge($item['badge']) : null,
        ];
    }

    /** @param array<string, mixed> $spec */
    private static function allows(array $spec, User $user): bool
    {
        $role = $spec['role'] ?? null;
        if ($role === 'developer' && ! $user->isDeveloper()) {
            return false;
        }
        if ($role === 'admin' && ! $user->isAdmin()) {
            return false;
        }

        $abilities = $spec['can'] ?? [];
        if ($abilities !== [] && ! $user->canAny($abilities)) {
            return false;
        }

        if (($spec['feature'] ?? null) === 'shop' && ! app(ShopFeatureFlag::class)->enabled()) {
            return false;
        }

        return true;
    }

    /** @param array<int, string> $patterns */
    private static function matches(array $patterns): bool
    {
        return $patterns !== [] && request()->routeIs($patterns);
    }

    /**
     * Contadores del menú. Cacheados 30s: invalidación natural por TTL, que
     * alcanza para un badge informativo.
     */
    private static function badge(string $key): ?int
    {
        if ($key !== 'web-reservations') {
            return null;
        }

        $count = Cache::remember(
            'shop.pending_web_count',
            30,
            fn () => Sale::where('source', 'web')
                ->where('status', SaleStatus::PENDING)
                ->count()
        );

        return $count > 0 ? $count : null;
    }
}
