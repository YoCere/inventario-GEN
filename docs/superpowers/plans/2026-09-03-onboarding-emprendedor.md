# Onboarding Rol Emprendedor (Nivel 1) — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Un rol "Emprendedor" (sin contabilidad) + un setting que oculta el toggle de factura en el POS + un checklist de bienvenida en el dashboard, para que el vendedor informal use un sistema limpio.

**Architecture:** Tres piezas aditivas: rol vía migración+seeder (el deploy solo corre migraciones), setting de negocio `facturacion_activada` que gatea el toggle del POS (vista) + un guard server-side en `SaleService`, y un checklist Blade condicionado al rol con estado derivado de datos.

**Tech Stack:** Laravel 11, Spatie Permission, PHPUnit + RefreshDatabase, Blade + Tailwind, Alpine. Sin dependencias nuevas.

**Spec:** `docs/superpowers/specs/2026-09-03-onboarding-emprendedor-design.md`

---

## Convenciones
- **NUNCA `migrate:fresh`**. Migraciones aditivas, idempotentes. El deploy corre `migrate --force` (NO seeders) → roles/permisos nuevos deben crearse en una **migración**, además del seeder para entornos sembrados.
- Tests: `extends Tests\TestCase`, `use RefreshDatabase` (corre migraciones, así el rol existe). Correr `php artisan test --filter <Clase>`.

## Anclajes verificados
- `database/seeders/RolesAndPermissionsSeeder.php`: `ROLE_PERMISSIONS` const (`:86-109`) con admin/staff; `run()` (`:111-134`) crea roles + `syncPermissions`. Permisos granulares ya existen (products.manage, sales.*, purchases.manage, shop.admin, finance.accounting, etc.).
- Hay una migración previa que crea permisos y asigna a admin/developer sin seeder (ver `tests/Feature/Authorization/FinancePermissionsTest.php::test_migration_creates_permissions_and_assigns_...`). Buscarla con `grep -rl "syncPermissions\|assignRole\|Permission::firstOrCreate" database/migrations` para mirar el patrón exacto.
- POS toggle: `resources/views/sales/create.blade.php:328` ("¿Factura? (NIT/CI)") — el control del `wantsInvoice` (Alpine, `:760`). Bloque de identidad fiscal en `:851`.
- `SaleService.php:176` persiste `'wants_invoice' => $data->wants_invoice` (choke point de todas las ventas).
- `resources/views/dashboard.blade.php` (ruta `dashboard`, `DashboardController@index`).
- `SettingGroups.php`: grupos + defaults + `labelFor` + `displayValue` (toggles boolean se muestran "Activo/Inactivo"). Editor por-key en `setting-form.blade.php`.

## File Structure
- Create migración rol emprendedor. Modify `database/seeders/RolesAndPermissionsSeeder.php`.
- Create migración setting `facturacion_activada`. Modify `app/Livewire/Settings/SettingGroups.php`.
- Modify `app/Services/SaleService.php` (guard). Modify `resources/views/sales/create.blade.php` (ocultar toggle).
- Modify `resources/views/dashboard.blade.php` (checklist) — o un partial `resources/views/partials/emprendedor-onboarding.blade.php`.
- Tests en `tests/Feature/`.

---

## Task 1: Rol Emprendedor (migración + seeder)

**Files:** Modify `database/seeders/RolesAndPermissionsSeeder.php` · Create `database/migrations/2026_09_03_000001_create_emprendedor_role.php` · Test `tests/Feature/Authorization/EmprendedorRoleTest.php`

- [ ] **Step 1: Test que falla**
```php
<?php

namespace Tests\Feature\Authorization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmprendedorRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_emprendedor_role_exists_with_expected_permissions(): void
    {
        // RefreshDatabase corre migraciones → el rol debe existir sin seedear.
        $rol = Role::findByName('emprendedor', 'web');

        foreach (['products.manage', 'sales.create', 'purchases.manage', 'shop.admin', 'dashboard.view'] as $perm) {
            $this->assertTrue($rol->hasPermissionTo($perm), "emprendedor debe tener {$perm}");
        }
        foreach (['finance.accounting', 'finance.view', 'users.payroll', 'audit.view', 'users.manage'] as $perm) {
            $this->assertFalse($rol->hasPermissionTo($perm), "emprendedor NO debe tener {$perm}");
        }
    }
}
```
- [ ] **Step 2: Correr — falla** (`php artisan test --filter EmprendedorRoleTest`)

- [ ] **Step 3: Migración** `database/migrations/2026_09_03_000001_create_emprendedor_role.php`. READ la migración de permisos existente (grep de arriba) para mirar cómo instancia Role/Permission + limpia cache. Base:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private array $perms = [
        'dashboard.view', 'products.view', 'products.manage', 'categories.manage', 'units.manage',
        'customers.manage', 'suppliers.manage', 'purchases.view', 'purchases.manage',
        'sales.view', 'sales.create', 'sales.complete', 'sales.cancel',
        'shop.admin', 'shop.landing.manage', 'settings.view', 'settings.edit-business',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Los permisos ya existen (creados por migraciones previas); firstOrCreate por si acaso.
        foreach ($this->perms as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $rol = Role::firstOrCreate(['name' => 'emprendedor', 'guard_name' => 'web']);
        $rol->syncPermissions($this->perms);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $rol = Role::where('name', 'emprendedor')->where('guard_name', 'web')->first();
        $rol?->delete();
    }
};
```
Verificar que TODOS los `$perms` existan como permisos válidos en el sistema (los nombres deben coincidir con los de `RolesAndPermissionsSeeder::PERMISSIONS`; ajustar si alguno difiere, p.ej. `settings.edit-business`).

- [ ] **Step 4: Seeder** — en `RolesAndPermissionsSeeder`: agregar la clave `'emprendedor' => [ ...misma lista... ]` a `ROLE_PERMISSIONS`, crear el rol en `run()` (`$emprendedor = Role::firstOrCreate(['name'=>'emprendedor','guard_name'=>'web']);`) y `$emprendedor->syncPermissions(self::ROLE_PERMISSIONS['emprendedor']);`. Idempotente.

- [ ] **Step 5: Correr — pasa**
- [ ] **Step 6: Commit** — `git commit -am "feat(roles): rol Emprendedor (sin contabilidad) via migracion + seeder"`

---

## Task 2: Setting `facturacion_activada`

**Files:** Create `database/migrations/2026_09_03_000002_seed_facturacion_setting.php` · Modify `app/Livewire/Settings/SettingGroups.php` · Test `tests/Feature/Settings/FacturacionSettingTest.php`

- [ ] **Step 1: Test que falla**
```php
<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacturacionSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_facturacion_default_off(): void
    {
        $this->assertSame('0', Setting::get('facturacion_activada'));
    }
}
```
- [ ] **Step 2: Correr — falla**
- [ ] **Step 3: Migración** `database/migrations/2026_09_03_000002_seed_facturacion_setting.php` (insert-if-missing):
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('settings')->where('key', 'facturacion_activada')->exists()) {
            DB::table('settings')->insert(['key' => 'facturacion_activada', 'value' => '0', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'facturacion_activada')->delete();
    }
};
```
- [ ] **Step 4: Exponer en Ajustes** — en `app/Livewire/Settings/SettingGroups.php`: agregar `'facturacion_activada'` al grupo `impuestos` (array de keys); default `'facturacion_activada' => '0'`; label `'facturacion_activada' => 'Facturación activada'`; y en `displayValue` tratarlo como boolean (agregarlo al brazo que devuelve `$value === '1' ? 'Activo' : 'Inactivo'`). En `setting-form.blade.php` agregar un `@elseif($key === 'facturacion_activada')` con un select Sí/No (mirar cómo lo hacen `tax_include_iva`/toggles existentes; si esos usan un select genérico por lista de booleanos, sumar la key a esa lista).
- [ ] **Step 5: Correr — pasa**
- [ ] **Step 6: Commit** — `git commit -am "feat(fiscal): setting facturacion_activada (default off) editable en Ajustes"`

---

## Task 3: Guard de facturación (SaleService) + ocultar toggle en POS

**Files:** Modify `app/Services/SaleService.php` · Modify `resources/views/sales/create.blade.php` · Test `tests/Feature/Sales/FacturacionGateTest.php`

- [ ] **Step 1: Test que falla**
```php
<?php

namespace Tests\Feature\Sales;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacturacionGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_wants_invoice_forced_false_when_facturacion_off(): void
    {
        Setting::set('facturacion_activada', '0');
        // Crear una venta COMPLETED con wants_invoice=true en el request/DTO y verificar que
        // la fila sale con wants_invoice=false. READ tests/Feature/Sales/SaleServiceTest.php
        // para el armado de createSale (SaleData / DTO, productos, etc.) y copiarlo.
        // Asertar: $sale->wants_invoice === false (o 0).
        $this->markTestIncomplete('Completar con el armado real de createSale copiado de SaleServiceTest');
    }

    public function test_wants_invoice_respected_when_facturacion_on(): void
    {
        Setting::set('facturacion_activada', '1');
        // Mismo armado; con facturación ON y un cliente con identidad fiscal, wants_invoice=true se respeta.
        $this->markTestIncomplete('Completar con el armado real de createSale');
    }
}
```
IMPORTANTE (implementador): READ `tests/Feature/Sales/SaleServiceTest.php` y `app/Services/SaleService.php` + el DTO de venta (`app/DTOs/SaleData.php` o similar) para armar `createSale` real. Reemplazá los `markTestIncomplete` por el flujo real: crear producto/stock/periodo, construir el `SaleData` con `wants_invoice=true`, llamar `createSale`, y asertar `wants_invoice` resultante. Dos casos: OFF → false; ON → true.

- [ ] **Step 2: Correr — falla** (una vez completado el armado)
- [ ] **Step 3: Implementar guard** — en `app/Services/SaleService.php`, donde hoy persiste `'wants_invoice' => $data->wants_invoice` (`:176`), gatear por el setting:
```php
                $facturacionOn = \App\Models\Setting::get('facturacion_activada', '0') === '1';
                // ... en el array del update:
                    'wants_invoice' => $data->wants_invoice && $facturacionOn,
```
Colocá `$facturacionOn` antes del `$sale->update([...])`. Con facturación off, la venta se persiste con `wants_invoice=false` aunque el DTO traiga true → no calcula IVA/IT ni postea líneas fiscales (que dependen de `wants_invoice`).

- [ ] **Step 4: Ocultar toggle en POS** — en `resources/views/sales/create.blade.php`, envolver el control "¿Factura? (NIT/CI)" (bloque ~línea 328) y el bloque de "Datos para factura" (~línea 851) en `@if(\App\Models\Setting::get('facturacion_activada','0') === '1') ... @endif`. READ el bloque para envolver exactamente el control del toggle sin romper el Alpine circundante (el `wantsInvoice` en el estado Alpine puede quedar; si el toggle no se renderiza, queda en su default false).

- [ ] **Step 5: Correr — pasa.** Regresión: `php artisan test --filter "SaleService|SaleFiscal|SaleTaxPosting|PosStoreWantsInvoice"` (el flujo de venta con factura, cuando `facturacion_activada=1`, sigue funcionando).
- [ ] **Step 6: Commit** — `git commit -am "feat(fiscal): guard de facturacion en SaleService + ocultar toggle si esta apagada"`

---

## Task 4: Checklist de bienvenida en el dashboard (rol Emprendedor)

**Files:** Modify `resources/views/dashboard.blade.php` (o crear `resources/views/partials/emprendedor-onboarding.blade.php` incluido ahí) · Test `tests/Feature/Onboarding/EmprendedorChecklistTest.php`

READ FIRST: `resources/views/dashboard.blade.php` + `app/Http/Controllers/DashboardController.php` (cómo se arma la vista, qué variables tiene, layout/clases). Para el rol usar `auth()->user()->hasRole('emprendedor')`; para los conteos, `\App\Models\Product::count()` y `\App\Models\Sale::count()` (o pasarlos desde el controller si preferís).

- [ ] **Step 1: Test que falla**
```php
<?php

namespace Tests\Feature\Onboarding;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmprendedorChecklistTest extends TestCase
{
    use RefreshDatabase;

    private function emprendedor(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('emprendedor');
        return $user;
    }

    public function test_checklist_visible_sin_productos(): void
    {
        $this->actingAs($this->emprendedor());
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Cargá tus productos');
    }

    public function test_checklist_oculto_con_productos_y_ventas(): void
    {
        // Con >=1 producto y >=1 venta, la tarjeta no aparece. READ SaleServiceTest para crear una venta,
        // o insertá directamente un Product y un Sale mínimos vía factory/create para los conteos.
        $this->markTestIncomplete('Crear 1 producto + 1 venta y asertar assertDontSee("Cargá tus productos")');
    }
}
```
Completá el 2º test creando un `Product` y un `Sale` (mínimos, por factory/create) para que ambos conteos sean > 0, y `assertDontSee('Cargá tus productos')`.

- [ ] **Step 2: Correr — falla**
- [ ] **Step 3: Implementar** — en `dashboard.blade.php`, cerca del inicio del contenido, agregar el checklist SOLO para emprendedores cuando falta algún paso:
```blade
@if(auth()->user()?->hasRole('emprendedor'))
    @php
        $tieneProductos = \App\Models\Product::query()->exists();
        $tieneVentas = \App\Models\Sale::query()->exists();
    @endphp
    @unless($tieneProductos && $tieneVentas)
        <div class="mb-6 rounded-lg border border-border bg-card p-5">
            <h3 class="text-lg font-semibold text-foreground">¡Bienvenido! Arrancá en 3 pasos</h3>
            <ul class="mt-3 space-y-2 text-sm">
                <li class="flex items-center gap-2">
                    <span>{{ $tieneProductos ? '✅' : '⬜' }}</span>
                    <a href="{{ route('products.index') }}" class="text-primary hover:underline">Cargá tus productos</a>
                </li>
                <li class="flex items-center gap-2">
                    <span>{{ $tieneVentas ? '✅' : '⬜' }}</span>
                    <a href="{{ route('sales.create') }}" class="text-primary hover:underline">Registrá tu primera venta</a>
                </li>
                <li class="flex items-center gap-2">
                    <span>▶️</span>
                    <a href="{{ route('sales.index') }}" class="text-primary hover:underline">Mirá tus ventas</a>
                </li>
            </ul>
        </div>
    @endunless
@endif
```
Verificar los nombres de ruta (`products.index`, `sales.create`, `sales.index`) con `php artisan route:list`; ajustar si difieren. Respetar dark mode / clases del dashboard.

- [ ] **Step 4: Correr — pasa**
- [ ] **Step 5: Commit** — `git commit -am "feat(onboarding): checklist de bienvenida en dashboard para rol Emprendedor"`

---

## Cierre
- [ ] **Suite completa** — `php artisan test`. Sin regresiones (todo aditivo).
- [ ] **Nota de deploy** — `php artisan migrate` (crea el rol + el setting). El rol "Emprendedor" se asigna a usuarios desde la gestión de roles/usuarios (admin). Facturación se prende en Ajustes → Impuestos cuando el negocio se formaliza.
- [ ] **Follow-ups (Nivel 2):** selector de perfil al primer login; dashboard "Mis ventas" dedicado; asignación de rol en el alta de usuario.

Al terminar → **superpowers:finishing-a-development-branch**.

---

## Self-review (checklist del autor)
- **Cobertura de spec:** R1 (T1 rol migración+seeder), R2 (T2 setting), R3 (T3 ocultar toggle), R4 (T3 guard SaleService), R5 (T4 checklist). ✔
- **Placeholders:** T1/T2 código completo. T3/T4 tienen `markTestIncomplete` a propósito porque el armado de `createSale`/venta depende del DTO real del repo (el implementador READ SaleServiceTest y lo completa) — están señalados con instrucción explícita, no son placeholders silenciosos. La lógica de producción (guard, blade) sí está completa. ✔
- **Consistencia:** `facturacion_activada` mismo key en migración/SettingGroups/SaleService/blade. Lista de permisos del rol idéntica en migración y seeder. `hasRole('emprendedor')` en checklist. ✔
- **Deploy:** rol vía MIGRACIÓN (no solo seeder) porque el deploy corre migrate sin db:seed. ✔
- **A verificar en implementación:** nombres exactos de permisos (`settings.edit-business` existe?); el bloque exacto del toggle en create.blade.php; el DTO de venta (SaleData) y su campo wants_invoice; nombres de ruta del checklist. ✔
