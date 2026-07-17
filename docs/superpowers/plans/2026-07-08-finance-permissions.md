# Menú Finanzas por permiso — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gatear todo el menú Finanzas por permisos granulares (Spatie) en vez de por rol hardcodeado, para que la pantalla dev-only de Roles controle el acceso por rol de forma ajustable.

**Architecture:** 4 permisos nuevos (`assets.manage`, `loans.manage`, `budgets.manage`, `production.manage`) sumados al seeder + una migración de datos aditiva para prod. Las rutas `/finance/*` pasan a `middleware('can:<permiso>')`, el menú a `@can`/`@canany`, y el bot `/reportes` a `can('finance.view')`. Sin cambio de comportamiento por defecto (admin+dev sí, staff no).

**Tech Stack:** Laravel 11, Spatie laravel-permission, PHPUnit, Blade.

**Regla MySQL:** nunca `migrate:fresh`. La migración es aditiva.

---

## Estructura de archivos

| Archivo | Responsabilidad |
|---------|-----------------|
| `database/seeders/RolesAndPermissionsSeeder.php` | Catálogo canónico: +4 permisos, +admin set (modificar) |
| `database/migrations/2026_07_08_140000_add_finance_menu_permissions.php` | Migración aditiva para entornos existentes (crear) |
| `routes/web.php` | Rutas finance gateadas por `can:` (modificar) |
| `resources/views/layouts/navigation.blade.php` | Menú escritorio + móvil con `@can`/`@canany` (modificar) |
| `app/Services/Telegram/BotHandler.php` | `cmdReports` → `can('finance.view')` (modificar) |
| `tests/Feature/Authorization/FinancePermissionsTest.php` | Tests de gating de rutas + seeder + migración (crear) |
| `tests/Feature/Authorization/FinanceMenuVisibilityTest.php` | Tests de visibilidad del menú (crear) |

---

## Task 1: Permisos nuevos en el seeder

**Files:**
- Modify: `database/seeders/RolesAndPermissionsSeeder.php`
- Test: `tests/Feature/Authorization/FinancePermissionsTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Authorization/FinancePermissionsTest.php`:

```php
<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinancePermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_grants_new_finance_permissions_to_admin_not_staff(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = Role::findByName('admin', 'web');
        $staff = Role::findByName('staff', 'web');

        foreach (['assets.manage', 'loans.manage', 'budgets.manage', 'production.manage'] as $perm) {
            $this->assertTrue($admin->hasPermissionTo($perm), "admin debe tener {$perm}");
            $this->assertFalse($staff->hasPermissionTo($perm), "staff NO debe tener {$perm}");
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Authorization/FinancePermissionsTest.php`
Expected: FAIL — permission `assets.manage` no existe (Spatie `PermissionDoesNotExist`) o admin no lo tiene.

- [ ] **Step 3: Add the 4 permissions to the seeder**

En `database/seeders/RolesAndPermissionsSeeder.php`, en la const `PERMISSIONS`, después de la línea
`'finance.accounting' => 'Plan de cuentas + libro diario + estados',` agregar:

```php
        'assets.manage' => 'Activos fijos y depreciación',
        'loans.manage' => 'Préstamos y amortización',
        'budgets.manage' => 'Presupuestos',
        'production.manage' => 'Producción y recetas (BOM)',
```

Y en `ROLE_PERMISSIONS['admin']`, después de la línea
`'finance.view', 'finance.accounting', 'users.payroll', 'products.kardex',` agregar:

```php
            'assets.manage', 'loans.manage', 'budgets.manage', 'production.manage',
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Authorization/FinancePermissionsTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add database/seeders/RolesAndPermissionsSeeder.php tests/Feature/Authorization/FinancePermissionsTest.php
git commit -m "feat(perms): 4 permisos de menu finanzas (assets/loans/budgets/production)"
```

---

## Task 2: Migración aditiva (entornos existentes)

**Files:**
- Create: `database/migrations/2026_07_08_140000_add_finance_menu_permissions.php`
- Test: `tests/Feature/Authorization/FinancePermissionsTest.php` (agregar caso)

- [ ] **Step 1: Write the migration**

`database/migrations/2026_07_08_140000_add_finance_menu_permissions.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Aditiva: crea los 4 permisos del menú finanzas y los asigna a developer + admin
 * SIN re-sincronizar (no borra permisos personalizados de ningún rol en prod).
 */
return new class extends Migration
{
    private const NEW_PERMISSIONS = ['assets.manage', 'loans.manage', 'budgets.manage', 'production.manage'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::NEW_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // developer recibe todos (consistencia; igual pasa por Gate::before).
        $developer = Role::where('name', 'developer')->where('guard_name', 'web')->first();
        $admin = Role::where('name', 'admin')->where('guard_name', 'web')->first();

        foreach (self::NEW_PERMISSIONS as $name) {
            $developer?->givePermissionTo($name);
            $admin?->givePermissionTo($name);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // No-op: no revocar permisos en un rollback (evita romper configuración del negocio).
    }
};
```

- [ ] **Step 2: Write the failing test (migration effect, sin seeder)**

Agregar a `tests/Feature/Authorization/FinancePermissionsTest.php`:

```php
    public function test_migration_creates_permissions_and_assigns_to_admin_developer_without_seeder(): void
    {
        // RefreshDatabase ya corrió la migración. NO seedeamos: probamos solo el efecto de la migración.
        $admin = Role::findByName('admin', 'web');
        $developer = Role::findByName('developer', 'web');

        foreach (['assets.manage', 'loans.manage', 'budgets.manage', 'production.manage'] as $perm) {
            $this->assertTrue(\Spatie\Permission\Models\Permission::where('name', $perm)->exists(), "{$perm} debe existir");
            $this->assertTrue($admin->hasPermissionTo($perm), "admin (via migración) debe tener {$perm}");
            $this->assertTrue($developer->hasPermissionTo($perm), "developer (via migración) debe tener {$perm}");
        }
    }
```

- [ ] **Step 3: Run tests**

Run: `php artisan test tests/Feature/Authorization/FinancePermissionsTest.php`
Expected: PASS (2 tests). Si el rol `admin`/`developer` no existe al correr la migración en el test,
verificar que la migración de roles base (`*_migrate_user_roles_to_spatie*` o similar) tiene timestamp
ANTERIOR a `2026_07_08_140000`. (Lo tiene: los roles base se crean en una migración temprana.)

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_07_08_140000_add_finance_menu_permissions.php tests/Feature/Authorization/FinancePermissionsTest.php
git commit -m "feat(perms): migracion aditiva de permisos de menu finanzas"
```

---

## Task 3: Rutas finance gateadas por `can:`

**Files:**
- Modify: `routes/web.php`
- Test: `tests/Feature/Authorization/FinancePermissionsTest.php` (agregar casos)

- [ ] **Step 1: Write the failing tests (route gating)**

Agregar a `tests/Feature/Authorization/FinancePermissionsTest.php` (usa el seeder para que los roles tengan permisos):

```php
    public function test_staff_cannot_access_finance_routes(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get(route('finance.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('finance.chart-of-accounts.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('finance.fixed-assets.index'))->assertForbidden();
    }

    public function test_admin_can_access_finance_routes(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('finance.index'))->assertOk();
        $this->actingAs($admin)->get(route('finance.chart-of-accounts.index'))->assertOk();
        $this->actingAs($admin)->get(route('finance.fixed-assets.index'))->assertOk();
    }

    public function test_gating_is_permission_driven_not_role(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        // Usuario SIN rol, solo con finance.view directo → ve resumen pero NO contabilidad.
        $user = User::factory()->create();
        $user->givePermissionTo('finance.view');

        $this->actingAs($user)->get(route('finance.index'))->assertOk();
        $this->actingAs($user)->get(route('finance.chart-of-accounts.index'))->assertForbidden();
    }

    public function test_developer_accesses_everything(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $dev = User::factory()->developer()->create();

        $this->actingAs($dev)->get(route('finance.index'))->assertOk();
        $this->actingAs($dev)->get(route('finance.production.index'))->assertOk();
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Authorization/FinancePermissionsTest.php`
Expected: FAIL — hoy `finance.index` no tiene middleware (staff recibe 200, no 403) y
`finance.chart-of-accounts.index` está sin gate por permiso.

- [ ] **Step 3: Restructure the finance route groups**

En `routes/web.php`, REEMPLAZAR los DOS grupos `Route::prefix('finance')...` actuales (el sin-middleware
y el `middleware('admin')`) por este único bloque (preserva TODOS los nombres de ruta):

```php
    Route::prefix('finance')->name('finance.')->group(function () {
        Route::middleware('can:finance.view')->group(function () {
            Route::view('/', 'finance.index')->name('index');
            Route::view('categories', 'finance-categories.index')->name('categories.index');
            Route::view('transactions', 'finance-transactions.index')->name('transactions.index');
            Route::get('transactions/print/{printId}', [FinanceReportController::class, 'print'])->name('transactions.print');
        });

        Route::middleware('can:finance.accounting')->group(function () {
            Route::view('chart-of-accounts', 'finance-chart-of-accounts.index')->name('chart-of-accounts.index');
            Route::view('journal-entries', 'finance-journal-entries.index')->name('journal-entries.index');
            Route::get('journal-entries/book', [\App\Http\Controllers\JournalBookController::class, 'index'])->name('journal-entries.book');
            Route::get('journal-entries/book/print', [\App\Http\Controllers\JournalBookController::class, 'print'])->name('journal-entries.book.print');
            Route::view('journal-entries/create', 'finance-journal-entries.create')->name('journal-entries.create');
            Route::get('statements', [FinancialStatementController::class, 'index'])->name('statements.index');
            Route::view('accounting-periods', 'finance-accounting-periods.index')->name('accounting-periods.index');
            Route::view('trial-balance', 'accounting.trial-balance')->name('trial-balance');
            Route::view('worksheet', 'accounting.worksheet')->name('worksheet');
        });

        Route::middleware('can:assets.manage')->group(function () {
            Route::view('asset-categories', 'asset-categories.index')->name('asset-categories.index');
            Route::view('fixed-assets', 'fixed-assets.index')->name('fixed-assets.index');
            Route::view('fixed-assets/{assetId}/schedule', 'fixed-assets.schedule')->name('fixed-assets.schedule');
        });

        Route::middleware('can:loans.manage')->group(function () {
            Route::view('loans', 'loans.index')->name('loans.index');
            Route::view('loans/{loan}/schedule', 'loans.schedule')->name('loans.schedule');
        });

        Route::middleware('can:budgets.manage')->group(function () {
            Route::view('budgets', 'budgets.index')->name('budgets.index');
            Route::view('budgets/{budget}/show', 'budgets.show')->name('budgets.show');
        });

        Route::middleware('can:production.manage')->group(function () {
            Route::view('boms', 'boms.index')->name('boms.index');
            Route::view('production', 'production.index')->name('production.index');
        });

        // Redirects legacy: sin gate propio (redirigen a páginas que ya gatean).
        Route::permanentRedirect('kardex', 'master/kardex')->name('kardex.legacy-redirect');
        Route::permanentRedirect('payroll', 'users/payroll')->name('payroll.legacy-redirect');
    });
```

(Los `use` de `FinanceReportController`, `FinancialStatementController` ya están al inicio de `routes/web.php`.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Authorization/FinancePermissionsTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add routes/web.php tests/Feature/Authorization/FinancePermissionsTest.php
git commit -m "feat(perms): rutas finanzas gateadas por permiso (cierra acceso URL de staff)"
```

---

## Task 4: Menú por `@can`/`@canany` (escritorio + móvil)

**Files:**
- Modify: `resources/views/layouts/navigation.blade.php`
- Test: `tests/Feature/Authorization/FinanceMenuVisibilityTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Authorization/FinanceMenuVisibilityTest.php`:

```php
<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceMenuVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_does_not_see_finance_menu(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Finanzas');
    }

    public function test_admin_sees_finance_menu(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Finanzas');
    }
}
```

(Si la ruta del inicio no se llama `dashboard`, usar la ruta real del home autenticado — verificar con
`php artisan route:list --name=dashboard`; si no existe, usar `route('products.index')` que staff sí ve.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Authorization/FinanceMenuVisibilityTest.php`
Expected: FAIL en `test_staff_does_not_see_finance_menu` — hoy el dropdown "Finanzas" se muestra a
todos (Resumen/Contabilidad/Tesorería no están gateados), así que staff ve "Finanzas".

- [ ] **Step 3: Gate the desktop menu**

En `resources/views/layouts/navigation.blade.php`, envolver TODO el `<x-nav-dropdown>` de Finanzas
(escritorio) con `@canany` y cada ítem con su `@can`. Reemplazar el bloque escritorio por:

```blade
                        @canany(['finance.view','finance.accounting','assets.manage','loans.manage','budgets.manage','production.manage'])
                        <x-nav-dropdown active="{{ request()->routeIs(['finance.*']) }}">
                            <x-slot name="icon">
                                <x-heroicon-o-currency-dollar class="mr-2 h-4 w-4" />
                            </x-slot>
                            <x-slot name="trigger">
                                Finanzas
                            </x-slot>
                            <x-slot name="content">
                                @can('finance.view')
                                <x-dropdown-link :href="route('finance.index')" :active="request()->routeIs('finance.index')">
                                    Resumen financiero
                                </x-dropdown-link>
                                @endcan
                                @can('finance.accounting')
                                <div class="my-1 border-t border-border"></div>
                                <div class="px-2 py-1 text-xs font-semibold text-muted-foreground uppercase tracking-wide">Contabilidad</div>
                                <x-dropdown-link :href="route('finance.chart-of-accounts.index')" :active="request()->routeIs('finance.chart-of-accounts.index')">
                                    Plan de cuentas
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('finance.journal-entries.index')" :active="request()->routeIs('finance.journal-entries.index')">
                                    Libro diario
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('finance.statements.index')" :active="request()->routeIs('finance.statements.index')">
                                    Estados financieros
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('finance.trial-balance')" :active="request()->routeIs('finance.trial-balance')">
                                    Balance de Sumas y Saldos
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('finance.worksheet')" :active="request()->routeIs('finance.worksheet')">
                                    Hoja Teórica
                                </x-dropdown-link>
                                @endcan
                                @can('assets.manage')
                                <div class="my-1 border-t border-border"></div>
                                <div class="px-2 py-1 text-xs font-semibold text-muted-foreground uppercase tracking-wide">Activos Fijos</div>
                                <x-dropdown-link :href="route('finance.asset-categories.index')" :active="request()->routeIs('finance.asset-categories.index')">
                                    Categorías de Activo
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('finance.fixed-assets.index')" :active="request()->routeIs('finance.fixed-assets.*')">
                                    Activos Fijos
                                </x-dropdown-link>
                                @endcan
                                @can('loans.manage')
                                <div class="my-1 border-t border-border"></div>
                                <div class="px-2 py-1 text-xs font-semibold text-muted-foreground uppercase tracking-wide">Préstamos</div>
                                <x-dropdown-link :href="route('finance.loans.index')" :active="request()->routeIs('finance.loans.*')">
                                    Préstamos
                                </x-dropdown-link>
                                @endcan
                                @can('budgets.manage')
                                <div class="my-1 border-t border-border"></div>
                                <div class="px-2 py-1 text-xs font-semibold text-muted-foreground uppercase tracking-wide">Presupuestos</div>
                                <x-dropdown-link :href="route('finance.budgets.index')" :active="request()->routeIs('finance.budgets.*')">
                                    Presupuestos
                                </x-dropdown-link>
                                @endcan
                                @can('production.manage')
                                <div class="my-1 border-t border-border"></div>
                                <div class="px-2 py-1 text-xs font-semibold text-muted-foreground uppercase tracking-wide">Producción</div>
                                <x-dropdown-link :href="route('finance.boms.index')" :active="request()->routeIs('finance.boms.*')">
                                    Recetas (BOM)
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('finance.production.index')" :active="request()->routeIs('finance.production.*')">
                                    Producción
                                </x-dropdown-link>
                                @endcan
                                @can('finance.view')
                                <div class="my-1 border-t border-border"></div>
                                <div class="px-2 py-1 text-xs font-semibold text-muted-foreground uppercase tracking-wide">Tesorería</div>
                                <x-dropdown-link :href="route('finance.transactions.index')" :active="request()->routeIs('finance.transactions.index')">
                                    Transacciones
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('finance.categories.index')" :active="request()->routeIs('finance.categories.index')">
                                    Categorías
                                </x-dropdown-link>
                                @endcan
                            </x-slot>
                        </x-nav-dropdown>
                        @endcanany
```

- [ ] **Step 4: Gate the mobile menu**

En el mismo archivo, envolver el acordeón MÓVIL de Finanzas con `@canany` y cada sección con su `@can`.
Reemplazar el bloque móvil por:

```blade
                        @canany(['finance.view','finance.accounting','assets.manage','loans.manage','budgets.manage','production.manage'])
                        <div x-data="{ expanded: {{ request()->routeIs(['finance.*']) ? 'true' : 'false' }} }" class="border-b-0">
                            <button @click="expanded = !expanded" class="flex flex-1 items-center justify-between py-0 font-semibold transition-all hover:underline [&[data-state=open]>svg]:rotate-180 w-full text-left text-md {{ request()->routeIs(['finance.*']) ? 'text-primary' : '' }}">
                                Finanzas
                                <x-heroicon-o-chevron-down :class="{'rotate-180': expanded}" class="h-4 w-4 shrink-0 transition-transform duration-200" />
                            </button>
                            <div x-show="expanded" x-collapse>
                                <div class="mt-2 flex flex-col gap-2 pl-4 border-l border-border ml-2">
                                    @can('finance.view')
                                    <a class="text-sm font-semibold py-1 {{ request()->routeIs('finance.index') ? 'text-primary' : '' }}" href="{{ route('finance.index') }}">Resumen financiero</a>
                                    @endcan
                                    @can('finance.accounting')
                                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground mt-2">Contabilidad</p>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.chart-of-accounts.index') ? 'text-primary' : '' }}" href="{{ route('finance.chart-of-accounts.index') }}">Plan de cuentas</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.journal-entries.index') ? 'text-primary' : '' }}" href="{{ route('finance.journal-entries.index') }}">Libro diario</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.statements.index') ? 'text-primary' : '' }}" href="{{ route('finance.statements.index') }}">Estados financieros</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.trial-balance') ? 'text-primary' : '' }}" href="{{ route('finance.trial-balance') }}">Balance de Sumas y Saldos</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.worksheet') ? 'text-primary' : '' }}" href="{{ route('finance.worksheet') }}">Hoja Teórica</a>
                                    @endcan
                                    @can('assets.manage')
                                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground mt-2">Activos Fijos</p>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.asset-categories.index') ? 'text-primary' : '' }}" href="{{ route('finance.asset-categories.index') }}">Categorías de Activo</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.fixed-assets.*') ? 'text-primary' : '' }}" href="{{ route('finance.fixed-assets.index') }}">Activos Fijos</a>
                                    @endcan
                                    @can('loans.manage')
                                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground mt-2">Préstamos</p>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.loans.*') ? 'text-primary' : '' }}" href="{{ route('finance.loans.index') }}">Préstamos</a>
                                    @endcan
                                    @can('budgets.manage')
                                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground mt-2">Presupuestos</p>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.budgets.*') ? 'text-primary' : '' }}" href="{{ route('finance.budgets.index') }}">Presupuestos</a>
                                    @endcan
                                    @can('production.manage')
                                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground mt-2">Producción</p>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.boms.*') ? 'text-primary' : '' }}" href="{{ route('finance.boms.index') }}">Recetas (BOM)</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.production.*') ? 'text-primary' : '' }}" href="{{ route('finance.production.index') }}">Producción</a>
                                    @endcan
                                    @can('finance.view')
                                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground mt-2">Tesorería</p>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.transactions.index') ? 'text-primary' : '' }}" href="{{ route('finance.transactions.index') }}">Transacciones</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.categories.index') ? 'text-primary' : '' }}" href="{{ route('finance.categories.index') }}">Categorias</a>
                                    @endcan
                                </div>
                            </div>
                        </div>
                        @endcanany
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Authorization/FinanceMenuVisibilityTest.php`
Expected: PASS (2 tests). Si `assertDontSee('Finanzas')` falla porque la palabra aparece en otro lado
de la página para staff, cambiar el aserto a algo más específico del menú, p.ej.
`assertDontSee('Resumen financiero')` y `assertSee('Resumen financiero')` respectivamente.

- [ ] **Step 6: Commit**

```bash
git add resources/views/layouts/navigation.blade.php tests/Feature/Authorization/FinanceMenuVisibilityTest.php
git commit -m "feat(perms): menu finanzas por @can/@canany (escritorio + movil)"
```

---

## Task 5: Bot `/reportes` por permiso

**Files:**
- Modify: `app/Services/Telegram/BotHandler.php`
- Test: `tests/Feature/Authorization/FinancePermissionsTest.php` (agregar caso)

`cmdReports` hoy hace `if (! $user || ! $user->isAdmin())`. Cambiar a permiso.

- [ ] **Step 1: Write the failing test**

Agregar a `tests/Feature/Authorization/FinancePermissionsTest.php`:

```php
    public function test_bot_reports_gated_by_finance_view_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $telegram = \Mockery::mock(\App\Services\Messaging\TelegramService::class);
        $sent = [];
        $telegram->shouldReceive('sendMessage')->andReturnUsing(function ($chatId, $msg) use (&$sent) {
            $sent[] = $msg; return [];
        });
        $this->app->instance(\App\Services\Messaging\TelegramService::class, $telegram);

        $staff = User::factory()->staff()->create();
        \App\Models\TelegramUser::create(['chat_id' => '777', 'user_id' => $staff->id, 'identifier' => 's', 'last_login' => now()]);

        app(\App\Services\Telegram\BotHandler::class)->dispatch(['message' => ['from' => ['id' => 777], 'text' => '/reportes']]);

        $this->assertTrue(
            collect($sent)->contains(fn ($m) => str_contains($m, 'restringid') || str_contains($m, 'permiso')),
            'staff sin finance.view debe ver mensaje de acceso restringido'
        );
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Authorization/FinancePermissionsTest.php --filter=test_bot_reports_gated`
Expected: puede PASAR ya (isAdmin() también excluye staff). Este test fija el comportamiento; el cambio
de código lo mantiene verde al pasar de rol → permiso. Si pasa en verde, continúa al Step 3 igual para
hacer el cambio de gating (rol → permiso) y re-verificar.

- [ ] **Step 3: Change the gate in cmdReports**

En `app/Services/Telegram/BotHandler.php`, en `cmdReports`, cambiar:

```php
        if (! $user || ! $user->isAdmin()) {
```
por:
```php
        if (! $user || ! $user->can('finance.view')) {
```

- [ ] **Step 4: Run test + confirm admin still allowed**

Run: `php artisan test tests/Feature/Authorization/FinancePermissionsTest.php`
Expected: PASS (todos). El admin tiene `finance.view` (seeder) → sigue viendo reportes.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Telegram/BotHandler.php tests/Feature/Authorization/FinancePermissionsTest.php
git commit -m "feat(perms): /reportes del bot gateado por can(finance.view) en vez de isAdmin"
```

---

## Cierre

- [ ] **Suite completa**: `php artisan test` — verde.
- [ ] **Smoke-test**: entrar como staff (no ve Finanzas ni por URL → 403), como admin (ve todo), y en la
  pantalla dev-only de Roles quitar `finance.view` al admin → confirmar que el admin deja de ver el
  resumen/transacciones (enforcement real).
- [ ] **Deploy**: `php artisan migrate` en prod (la migración aditiva crea + asigna los 4 permisos; NO
  `migrate:fresh`).
- [ ] **Code review** con `superpowers:requesting-code-review` sobre `feature/finance-permissions`.

## Cobertura del spec (self-review)

| Requisito | Task |
|-----------|------|
| R1 4 permisos nuevos en seeder + admin set | Task 1 |
| R2 migración aditiva no destructiva | Task 2 |
| R3 rutas por `can:` (cierra hueco URL staff) | Task 3 |
| R4 menú por `@can`/`@canany` (escritorio+móvil) | Task 4 |
| R5 bot `/reportes` por `can(finance.view)` | Task 5 |
| R6 default admin+dev sí / staff no, ajustable por UI Roles | Tasks 1-3 (seeder default + enforcement por permiso) |

**Fuera de alcance:** gating por-permiso de las tools IA de finanzas del agente (follow-up).
