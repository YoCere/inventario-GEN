# Editor de la landing de la tienda — Plan SP2

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Una pantalla en Ajustes donde se arma la landing de `/tienda`: reordenar secciones, escribir texto con formato, subir imágenes, activar/desactivar y publicar.

**Architecture:** Página dedicada `settings/tienda/landing` gateada por el permiso nuevo `shop.landing.manage`. Dos componentes Livewire con responsabilidades separadas: `LandingEditor` (estructura: lista, orden, alta/baja, publicar) y `LandingSectionForm` (contenido de una sección: campos, validación, saneo, imágenes), comunicados por eventos. El registry `SectionTypes` de SP1 se extiende con `form` (partial del formulario) y `rules` (validación), de modo que un tipo nuevo son 3 archivos y ningún cambio en el editor. Un servicio `LandingImages` centraliza guardar/borrar archivos.

**Tech Stack:** Laravel 11, Livewire 3 (trae Alpine), Blade, Tailwind, PHPUnit (class-style), MySQL. Dependencia nueva: `trix` (npm).

**Spec:** `docs/superpowers/specs/2026-07-20-shop-landing-editor-design.md`

---

## Convenciones del repo (leer antes de empezar)

- **NUNCA `migrate:fresh`/`migrate:refresh`** (MySQL dev compartido). Migraciones **aditivas** e idempotentes.
- Tests: clase PHPUnit, `extends Tests\TestCase`, `use RefreshDatabase`. Para rutas del shop público existe `tests/Feature/Shop/EnablesShop.php` (`enableShop()`), **necesario solo si el test toca `/tienda`**.
- Correr: `php artisan test --filter <Clase>`. Suite completa: `php artisan test` (tarda ~10 min).
- Livewire tests: `Livewire::test(Componente::class)`; auto-discovery de componentes en `App\Livewire`.
- Blade de admin usa tokens del design system: `text-foreground`, `text-muted-foreground`, `border-border`, `bg-muted`, `bg-background`, componentes `<x-primary-button>`, `<x-secondary-button>`, iconos `<x-heroicon-o-*>`.
- Layout de páginas admin: `<x-app-layout title="…">` con `<x-slot name="header">`.
- Permisos: Spatie. `developer` pasa por `Gate::before`.

## Estado heredado de SP1 (ya en main, no reimplementar)

- `App\Shop\Models\LandingSection` — `type`, `sort_order`, `is_enabled`, `data` (array cast), scopes `enabled()`/`ordered()`.
- `App\Shop\Landing\SectionTypes` — `map()` privado con `label`/`partial`/`default` por tipo; accesores `keys()`, `exists()`, `label()`, `partial()`, `defaultData()`.
- `App\Shop\Services\LandingHtmlSanitizer::sanitize(?string): string`.
- `App\Shop\Landing\LandingUrl` — `target()`, `safeUrl()`, `safeStoragePath()`.
  **OJO:** `target()` y `safeUrl()` llaman `route('shop.catalog')`, y esa ruta **solo se registra con `shop_enabled='1'`**. En el editor (admin, la tienda puede estar apagada) NO se pueden usar: por eso Task 1 agrega `isSafeUrl()`, que es puro y no resuelve rutas.
- `Database\Seeders\DefaultLandingTemplateSeeder` — siembra la plantilla por defecto (hoy con copy hardcodeado; Task 8 lo unifica con el registry).
- Partials de render en `resources/views/shop/landing/sections/{tipo}.blade.php` para los 7 tipos.

---

## File Structure

- Modify `app/Shop/Landing/SectionTypes.php` — agrega `form` + `rules` por tipo y sus accesores.
- Modify `app/Shop/Landing/LandingUrl.php` — agrega `isSafeUrl()` (validador puro).
- Create `app/Shop/Landing/LandingImages.php` — guardar/borrar imágenes de secciones.
- Modify `database/seeders/RolesAndPermissionsSeeder.php` — permiso `shop.landing.manage`.
- Create `database/migrations/2026_07_20_120000_add_shop_landing_manage_permission.php` — migración aditiva.
- Modify `routes/web.php` — ruta `settings.shop-landing`.
- Create `resources/views/settings/landing.blade.php` — página que monta el editor.
- Create `app/Livewire/Settings/LandingEditor.php` + `resources/views/livewire/settings/landing-editor.blade.php`.
- Create `app/Livewire/Settings/LandingSectionForm.php` + `resources/views/livewire/settings/landing-section-form.blade.php`.
- Create `resources/views/settings/landing/forms/{hero,about,hours,categories,gallery,contact,cta}.blade.php`.
- Modify `resources/js/app.js` + `package.json` — Trix.
- Modify `resources/views/livewire/settings/setting-groups.blade.php` — botón "Editar landing →".
- Tests: `tests/Feature/Settings/LandingEditorAccessTest.php`, `LandingEditorTest.php`, `LandingSectionFormTest.php`, `LandingImagesTest.php`, `SectionTypesContractTest.php`; modificar `tests/Feature/Shop/SectionTypesTest.php`.

---

## Task 1: Registry extendido (`form` + `rules`) y `LandingUrl::isSafeUrl`

**Files:**
- Modify: `app/Shop/Landing/SectionTypes.php`
- Modify: `app/Shop/Landing/LandingUrl.php`
- Test: `tests/Feature/Shop/SectionTypesTest.php` (agregar), `tests/Feature/Shop/LandingUrlTest.php` (agregar)

- [ ] **Step 1: Escribir los tests que fallan**

Agregar a `tests/Feature/Shop/SectionTypesTest.php`:
```php
    public function test_every_type_declares_form_partial_and_rules(): void
    {
        foreach (SectionTypes::keys() as $type) {
            $this->assertNotEmpty(SectionTypes::form($type), "Tipo {$type} sin partial de formulario");
            $this->assertNotEmpty(SectionTypes::rules($type), "Tipo {$type} sin reglas de validación");
        }
    }

    public function test_form_partial_path_follows_convention(): void
    {
        $this->assertSame('settings.landing.forms.hero', SectionTypes::form('hero'));
    }

    public function test_rules_are_keyed_by_field(): void
    {
        $this->assertArrayHasKey('heading', SectionTypes::rules('hero'));
    }
```

Agregar a `tests/Feature/Shop/LandingUrlTest.php`:
```php
    public function test_is_safe_url_accepts_http_and_relative_only(): void
    {
        $this->assertTrue(LandingUrl::isSafeUrl('https://example.com'));
        $this->assertTrue(LandingUrl::isSafeUrl('http://example.com/x'));
        $this->assertTrue(LandingUrl::isSafeUrl('/pagina'));

        $this->assertFalse(LandingUrl::isSafeUrl('javascript:alert(1)'));
        $this->assertFalse(LandingUrl::isSafeUrl('data:text/html,x'));
        $this->assertFalse(LandingUrl::isSafeUrl('example.com'));
        $this->assertFalse(LandingUrl::isSafeUrl(''));
        $this->assertFalse(LandingUrl::isSafeUrl(null));
    }
```

- [ ] **Step 2: Correr — deben fallar**

Run: `php artisan test --filter "SectionTypesTest|LandingUrlTest"`
Expected: FAIL — `Call to undefined method ... form()/rules()/isSafeUrl()`.

- [ ] **Step 3: Agregar `isSafeUrl` a `LandingUrl`**

En `app/Shop/Landing/LandingUrl.php`, agregar este método y hacer que `safeUrl()` lo use (sin cambiar su comportamiento público):
```php
    /**
     * Valida sin resolver rutas: true solo para http(s) absolutas o rutas relativas ('/...').
     * Puro a propósito — el editor corre en admin, donde route('shop.catalog') puede no existir
     * (las rutas de la tienda solo se registran con shop_enabled='1').
     */
    public static function isSafeUrl(?string $url): bool
    {
        $url = trim((string) $url);

        return $url !== '' && (str_starts_with($url, '/') || (bool) preg_match('#^https?://#i', $url));
    }
```
Y reemplazar el cuerpo de `safeUrl()` por:
```php
    public static function safeUrl(?string $url): string
    {
        return self::isSafeUrl($url) ? trim((string) $url) : route('shop.catalog');
    }
```

- [ ] **Step 4: Extender `SectionTypes`**

En `app/Shop/Landing/SectionTypes.php`, agregar a CADA entrada de `map()` las claves `form` y `rules`, y agregar los dos accesores. El `form` sigue la convención `settings.landing.forms.{tipo}`.

Entradas (agregar estas dos claves a las 7 que ya existen, sin tocar `label`/`partial`/`default`):
```php
            'hero' => [
                // … label, partial, default existentes …
                'form' => 'settings.landing.forms.hero',
                'rules' => [
                    'heading' => ['required', 'string', 'max:120'],
                    'subheading' => ['nullable', 'string', 'max:200'],
                    'cta_text' => ['nullable', 'string', 'max:40'],
                    'cta_target' => ['nullable', 'string', 'max:255'],
                    'background_image_path' => ['nullable', 'string', 'max:255'],
                ],
            ],
            'about' => [
                'form' => 'settings.landing.forms.about',
                'rules' => [
                    'heading' => ['nullable', 'string', 'max:120'],
                    'body_html' => ['nullable', 'string', 'max:20000'],
                    'image_path' => ['nullable', 'string', 'max:255'],
                ],
            ],
            'hours' => [
                'form' => 'settings.landing.forms.hours',
                'rules' => [
                    'heading' => ['nullable', 'string', 'max:120'],
                    'rows' => ['array', 'max:20'],
                    'rows.*.label' => ['required', 'string', 'max:60'],
                    'rows.*.value' => ['required', 'string', 'max:60'],
                ],
            ],
            'categories' => [
                'form' => 'settings.landing.forms.categories',
                'rules' => [
                    'heading' => ['nullable', 'string', 'max:120'],
                    'source' => ['required', 'in:auto,manual'],
                    'items' => ['array', 'max:20'],
                    'items.*.label' => ['required', 'string', 'max:60'],
                    'items.*.link' => ['nullable', 'string', 'max:255'],
                ],
            ],
            'gallery' => [
                'form' => 'settings.landing.forms.gallery',
                'rules' => [
                    'heading' => ['nullable', 'string', 'max:120'],
                    'images' => ['array', 'max:24'],
                    'images.*' => ['string', 'max:255'],
                ],
            ],
            'contact' => [
                'form' => 'settings.landing.forms.contact',
                'rules' => [
                    'heading' => ['nullable', 'string', 'max:120'],
                    'whatsapp' => ['nullable', 'string', 'max:30'],
                    'address' => ['nullable', 'string', 'max:200'],
                    'email' => ['nullable', 'email', 'max:120'],
                ],
            ],
            'cta' => [
                'form' => 'settings.landing.forms.cta',
                'rules' => [
                    'heading' => ['nullable', 'string', 'max:120'],
                    'text' => ['nullable', 'string', 'max:200'],
                    'button_text' => ['required', 'string', 'max:40'],
                    'target' => ['nullable', 'string', 'max:255'],
                ],
            ],
```

Accesores nuevos (junto a los existentes):
```php
    public static function form(string $type): ?string
    {
        return self::map()[$type]['form'] ?? null;
    }

    /** @return array<string, array<int,string>> reglas keyed por campo del `data` */
    public static function rules(string $type): array
    {
        return self::map()[$type]['rules'] ?? [];
    }
```

- [ ] **Step 5: Correr — deben pasar**

Run: `php artisan test --filter "SectionTypesTest|LandingUrlTest"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Shop/Landing/SectionTypes.php app/Shop/Landing/LandingUrl.php tests/Feature/Shop/SectionTypesTest.php tests/Feature/Shop/LandingUrlTest.php
git commit -m "feat(shop): registry de secciones declara formulario y reglas; LandingUrl::isSafeUrl"
```

---

## Task 2: Permiso `shop.landing.manage` + ruta + página

**Files:**
- Modify: `database/seeders/RolesAndPermissionsSeeder.php`
- Create: `database/migrations/2026_07_20_120000_add_shop_landing_manage_permission.php`
- Modify: `routes/web.php`
- Create: `resources/views/settings/landing.blade.php`
- Create: `app/Livewire/Settings/LandingEditor.php` (mínimo) + `resources/views/livewire/settings/landing-editor.blade.php` (mínimo)
- Test: `tests/Feature/Settings/LandingEditorAccessTest.php`

- [ ] **Step 1: Escribir el test que falla**

`tests/Feature/Settings/LandingEditorAccessTest.php`:
```php
<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingEditorAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_open_the_landing_editor(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('settings.shop-landing'))->assertOk();
    }

    public function test_staff_without_permission_gets_403(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($staff)->get(route('settings.shop-landing'))->assertForbidden();
    }

    public function test_developer_can_open_it(): void
    {
        $dev = User::factory()->create();
        $dev->assignRole('developer');

        $this->actingAs($dev)->get(route('settings.shop-landing'))->assertOk();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('settings.shop-landing'))->assertRedirect(route('login'));
    }
}
```

Nota: si `User::factory()` de este repo no acepta `assignRole` directo o ya trae estados (`->admin()`), usar el estado existente — revisar `database/factories/UserFactory.php` y seguir lo que hacen los tests de permisos ya escritos (por ejemplo los del trabajo de Finanzas).

- [ ] **Step 2: Correr — debe fallar**

Run: `php artisan test --filter LandingEditorAccessTest`
Expected: FAIL — `Route [settings.shop-landing] not defined`.

- [ ] **Step 3: Agregar el permiso al seeder**

En `database/seeders/RolesAndPermissionsSeeder.php`, en `PERMISSIONS`, junto al bloque "Tienda en línea (Shop module)":
```php
        // Tienda en línea (Shop module)
        'shop.admin' => 'Gestionar reservas web del catálogo público',
        'shop.landing.manage' => 'Editar la página de presentación de la tienda',
```
Y en `ROLE_PERMISSIONS['admin']`, junto a `'shop.admin',`:
```php
            'shop.admin', 'shop.landing.manage',
```

- [ ] **Step 4: Crear la migración aditiva**

`database/migrations/2026_07_20_120000_add_shop_landing_manage_permission.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Aditiva: crea shop.landing.manage y lo asigna a developer + admin SIN
 * re-sincronizar (no borra permisos personalizados de ningún rol en prod).
 */
return new class extends Migration
{
    private const NEW_PERMISSION = 'shop.landing.manage';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => self::NEW_PERMISSION, 'guard_name' => 'web']);

        Role::where('name', 'developer')->where('guard_name', 'web')->first()?->givePermissionTo(self::NEW_PERMISSION);
        Role::where('name', 'admin')->where('guard_name', 'web')->first()?->givePermissionTo(self::NEW_PERMISSION);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // No-op: no revocar permisos en un rollback.
    }
};
```

- [ ] **Step 5: Agregar la ruta**

En `routes/web.php`, **después** del bloque `Route::middleware('admin')->group(function () { … });` que termina con `Route::view('settings', 'settings.index')->name('settings.index');` y **antes** del grupo `Route::middleware('developer')`, agregar:
```php
    // Editor de la landing de la tienda — por permiso (delegable sin dar todo Ajustes).
    // Va acá y no en routes/shop-admin.php a propósito: esas rutas solo se cargan con
    // shop_enabled='1', y hay que poder preparar la landing antes de publicar la tienda.
    Route::view('settings/tienda/landing', 'settings.landing')
        ->middleware('can:shop.landing.manage')
        ->name('settings.shop-landing');
```

- [ ] **Step 6: Crear la página**

`resources/views/settings/landing.blade.php`:
```blade
<x-app-layout title="Landing de la tienda">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-foreground leading-tight">
            {{ __('Landing de la tienda') }}
        </h2>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <livewire:settings.landing-editor />
        </div>
    </div>
</x-app-layout>
```

- [ ] **Step 7: Crear el componente mínimo**

`app/Livewire/Settings/LandingEditor.php`:
```php
<?php

namespace App\Livewire\Settings;

use App\Shop\Models\LandingSection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class LandingEditor extends Component
{
    #[Computed]
    public function sections()
    {
        return LandingSection::ordered()->get();
    }

    public function render()
    {
        return view('livewire.settings.landing-editor');
    }
}
```

`resources/views/livewire/settings/landing-editor.blade.php`:
```blade
<div>
    <p class="text-sm text-muted-foreground">{{ $this->sections->count() }} secciones</p>
</div>
```

- [ ] **Step 8: Correr — debe pasar**

Run: `php artisan test --filter LandingEditorAccessTest`
Expected: PASS (4 tests).

- [ ] **Step 9: Commit**

```bash
git add database/seeders/RolesAndPermissionsSeeder.php database/migrations/2026_07_20_120000_add_shop_landing_manage_permission.php routes/web.php resources/views/settings/landing.blade.php app/Livewire/Settings/LandingEditor.php resources/views/livewire/settings/landing-editor.blade.php tests/Feature/Settings/LandingEditorAccessTest.php
git commit -m "feat(settings): ruta y permiso del editor de landing (shop.landing.manage)"
```

---

## Task 3: Servicio `LandingImages`

**Files:**
- Create: `app/Shop/Landing/LandingImages.php`
- Test: `tests/Feature/Settings/LandingImagesTest.php`

- [ ] **Step 1: Escribir el test que falla**

`tests/Feature/Settings/LandingImagesTest.php`:
```php
<?php

namespace Tests\Feature\Settings;

use App\Shop\Landing\LandingImages;
use App\Shop\Models\LandingSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LandingImagesTest extends TestCase
{
    use RefreshDatabase;

    private LandingImages $images;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->images = new LandingImages();
    }

    public function test_store_puts_file_under_shop_landing(): void
    {
        $path = $this->images->store(UploadedFile::fake()->image('foto.jpg'));

        $this->assertStringStartsWith('shop/landing/', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_delete_removes_the_file(): void
    {
        $path = $this->images->store(UploadedFile::fake()->image('foto.jpg'));

        $this->images->delete($path);

        Storage::disk('public')->assertMissing($path);
    }

    public function test_delete_ignores_null_and_missing(): void
    {
        $this->images->delete(null);
        $this->images->delete('shop/landing/no-existe.jpg');

        $this->assertTrue(true); // no lanza
    }

    public function test_delete_for_section_removes_every_image_it_references(): void
    {
        $bg = $this->images->store(UploadedFile::fake()->image('bg.jpg'));
        $one = $this->images->store(UploadedFile::fake()->image('uno.jpg'));
        $two = $this->images->store(UploadedFile::fake()->image('dos.jpg'));

        $section = LandingSection::create([
            'type' => 'gallery',
            'sort_order' => 0,
            'is_enabled' => true,
            'data' => ['background_image_path' => $bg, 'images' => [$one, $two]],
        ]);

        $this->images->deleteForSection($section);

        Storage::disk('public')->assertMissing($bg);
        Storage::disk('public')->assertMissing($one);
        Storage::disk('public')->assertMissing($two);
    }
}
```

- [ ] **Step 2: Correr — debe fallar**

Run: `php artisan test --filter LandingImagesTest`
Expected: FAIL — clase no encontrada.

- [ ] **Step 3: Implementar el servicio**

`app/Shop/Landing/LandingImages.php`:
```php
<?php

namespace App\Shop\Landing;

use App\Shop\Models\LandingSection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Guarda y borra las imágenes de las secciones de la landing. Centralizado para
 * que borrar una sección no deje archivos huérfanos en el disco.
 */
class LandingImages
{
    /** Campos del `data` que contienen UNA ruta de imagen. */
    private const SINGLE_KEYS = ['background_image_path', 'image_path'];

    /** Campos del `data` que contienen una LISTA de rutas. */
    private const LIST_KEYS = ['images'];

    public function store(UploadedFile $file): string
    {
        return $file->store('shop/landing', 'public');
    }

    public function delete(?string $path): void
    {
        if (! $path) {
            return;
        }

        try {
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        } catch (\Throwable $e) {
            // El registro en DB manda: un fallo de disco no aborta la operación.
            Log::warning('No se pudo borrar imagen de landing', ['path' => $path, 'error' => $e->getMessage()]);
        }
    }

    /** Borra todas las imágenes referenciadas por el `data` de una sección. */
    public function deleteForSection(LandingSection $section): void
    {
        $data = $section->data ?? [];

        foreach (self::SINGLE_KEYS as $key) {
            $this->delete($data[$key] ?? null);
        }

        foreach (self::LIST_KEYS as $key) {
            foreach ((array) ($data[$key] ?? []) as $path) {
                $this->delete(is_string($path) ? $path : null);
            }
        }
    }
}
```

- [ ] **Step 4: Correr — debe pasar**

Run: `php artisan test --filter LandingImagesTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Shop/Landing/LandingImages.php tests/Feature/Settings/LandingImagesTest.php
git commit -m "feat(shop): servicio LandingImages para archivos de secciones"
```

---

## Task 4: `LandingEditor` completo (lista, orden, alta/baja, publicar)

**Files:**
- Modify: `app/Livewire/Settings/LandingEditor.php`
- Modify: `resources/views/livewire/settings/landing-editor.blade.php`
- Test: `tests/Feature/Settings/LandingEditorTest.php`

- [ ] **Step 1: Escribir el test que falla**

`tests/Feature/Settings/LandingEditorTest.php`:
```php
<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\LandingEditor;
use App\Models\Setting;
use App\Shop\Models\LandingSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LandingEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // La migración sembradora de SP1 llena la tabla en cada build; estos tests
        // necesitan controlar el contenido exacto.
        LandingSection::query()->delete();
    }

    private function makeSection(string $type, int $order): LandingSection
    {
        return LandingSection::create([
            'type' => $type,
            'sort_order' => $order,
            'is_enabled' => true,
            'data' => ['heading' => strtoupper($type)],
        ]);
    }

    public function test_add_section_creates_row_with_default_data(): void
    {
        Livewire::test(LandingEditor::class)
            ->call('addSection', 'hours');

        $section = LandingSection::where('type', 'hours')->firstOrFail();
        $this->assertTrue($section->is_enabled);
        $this->assertArrayHasKey('rows', $section->data);
    }

    public function test_add_section_rejects_unknown_type(): void
    {
        Livewire::test(LandingEditor::class)
            ->call('addSection', 'inexistente');

        $this->assertSame(0, LandingSection::count());
    }

    public function test_move_down_swaps_with_next(): void
    {
        $a = $this->makeSection('hero', 0);
        $b = $this->makeSection('about', 1);

        Livewire::test(LandingEditor::class)->call('move', $a->id, 'down');

        $this->assertSame(['about', 'hero'], LandingSection::ordered()->pluck('type')->all());
        $this->assertTrue(true, (string) $b->id);
    }

    public function test_move_up_swaps_with_previous(): void
    {
        $this->makeSection('hero', 0);
        $b = $this->makeSection('about', 1);

        Livewire::test(LandingEditor::class)->call('move', $b->id, 'up');

        $this->assertSame(['about', 'hero'], LandingSection::ordered()->pluck('type')->all());
    }

    public function test_move_at_the_edge_does_nothing(): void
    {
        $a = $this->makeSection('hero', 0);
        $this->makeSection('about', 1);

        Livewire::test(LandingEditor::class)->call('move', $a->id, 'up');

        $this->assertSame(['hero', 'about'], LandingSection::ordered()->pluck('type')->all());
    }

    public function test_toggle_enabled_flips_the_flag(): void
    {
        $a = $this->makeSection('hero', 0);

        Livewire::test(LandingEditor::class)->call('toggleEnabled', $a->id);

        $this->assertFalse($a->fresh()->is_enabled);
    }

    public function test_delete_section_removes_the_row(): void
    {
        $a = $this->makeSection('hero', 0);

        Livewire::test(LandingEditor::class)->call('deleteSection', $a->id);

        $this->assertNull(LandingSection::find($a->id));
    }

    public function test_publish_switch_writes_the_setting(): void
    {
        Livewire::test(LandingEditor::class)
            ->set('landingEnabled', false);

        $this->assertSame('0', Setting::get('shop_landing_enabled'));
    }

    public function test_selecting_a_section_dispatches_the_event(): void
    {
        $a = $this->makeSection('hero', 0);

        Livewire::test(LandingEditor::class)
            ->call('select', $a->id)
            ->assertDispatched('landing-section-selected');
    }
}
```

- [ ] **Step 2: Correr — debe fallar**

Run: `php artisan test --filter LandingEditorTest`
Expected: FAIL — métodos inexistentes.

- [ ] **Step 3: Implementar el componente**

`app/Livewire/Settings/LandingEditor.php` (reemplazar el mínimo de Task 2):
```php
<?php

namespace App\Livewire\Settings;

use App\Models\Setting;
use App\Shop\Landing\LandingImages;
use App\Shop\Landing\SectionTypes;
use App\Shop\Models\LandingSection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Estructura de la landing: qué secciones hay, en qué orden, cuáles están activas
 * y si la landing se publica. NO edita el contenido — de eso se ocupa LandingSectionForm.
 */
class LandingEditor extends Component
{
    public ?int $selectedId = null;

    public bool $landingEnabled = true;

    public function mount(): void
    {
        $this->landingEnabled = Setting::get('shop_landing_enabled', '1') === '1';
    }

    #[Computed]
    public function sections()
    {
        return LandingSection::ordered()->get();
    }

    /** @return array<string,string> tipo => label, para el menú "agregar sección" */
    #[Computed]
    public function availableTypes(): array
    {
        return collect(SectionTypes::keys())
            ->mapWithKeys(fn ($type) => [$type => SectionTypes::label($type)])
            ->all();
    }

    public function updatedLandingEnabled(): void
    {
        Setting::set('shop_landing_enabled', $this->landingEnabled ? '1' : '0');
    }

    public function select(int $id): void
    {
        $this->selectedId = $id;
        $this->dispatch('landing-section-selected', id: $id);
    }

    public function addSection(string $type): void
    {
        if (! SectionTypes::exists($type)) {
            return;
        }

        $section = LandingSection::create([
            'type' => $type,
            'sort_order' => (int) (LandingSection::max('sort_order') ?? -1) + 1,
            'is_enabled' => true,
            'data' => SectionTypes::defaultData($type),
        ]);

        unset($this->sections);
        $this->select($section->id);
    }

    public function move(int $id, string $direction): void
    {
        $ordered = LandingSection::ordered()->get();
        $index = $ordered->search(fn ($s) => $s->id === $id);

        if ($index === false) {
            return;
        }

        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if ($target < 0 || $target >= $ordered->count()) {
            return;
        }

        // Reasignar 0..n-1 sobre el orden ya intercambiado: robusto aunque los
        // sort_order vengan duplicados o con huecos.
        $reordered = $ordered->values();
        $moved = $reordered->pull($index);
        $reordered = $reordered->values();
        $reordered->splice($target, 0, [$moved]);

        foreach ($reordered->values() as $position => $section) {
            $section->update(['sort_order' => $position]);
        }

        unset($this->sections);
    }

    public function toggleEnabled(int $id): void
    {
        $section = LandingSection::find($id);
        $section?->update(['is_enabled' => ! $section->is_enabled]);

        unset($this->sections);
    }

    public function deleteSection(int $id): void
    {
        $section = LandingSection::find($id);
        if (! $section) {
            return;
        }

        app(LandingImages::class)->deleteForSection($section);
        $section->delete();

        if ($this->selectedId === $id) {
            $this->selectedId = null;
            $this->dispatch('landing-section-cleared');
        }

        unset($this->sections);
    }

    #[On('landing-sections-changed')]
    public function refreshSections(): void
    {
        unset($this->sections);
    }

    public function render()
    {
        return view('livewire.settings.landing-editor');
    }
}
```

- [ ] **Step 4: Implementar el blade**

`resources/views/livewire/settings/landing-editor.blade.php`:
```blade
<div class="space-y-4">

    {{-- Barra superior: publicar + ver tienda --}}
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-background p-4">
        <label class="flex items-center gap-2 text-sm font-medium text-foreground">
            <input type="checkbox" wire:model.live="landingEnabled" class="rounded border-input">
            Mostrar la landing en /tienda
        </label>

        <div class="flex items-center gap-2">
            <span class="text-xs text-muted-foreground">
                @if($landingEnabled)
                    Los visitantes ven esta página al entrar.
                @else
                    Los visitantes entran directo al catálogo.
                @endif
            </span>
            @if(\Illuminate\Support\Facades\Route::has('shop.index'))
                <a href="{{ route('shop.index') }}" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-1 px-3 py-2 rounded-md border border-input bg-background text-sm hover:bg-accent">
                    Ver tienda ↗
                </a>
            @endif
        </div>
    </div>

    <div class="grid lg:grid-cols-[minmax(0,340px)_1fr] gap-4">

        {{-- Columna izquierda: lista de secciones --}}
        <div class="rounded-lg border border-border bg-background">
            <div class="flex items-center justify-between border-b border-border px-4 py-3">
                <h3 class="text-sm font-semibold text-foreground">Secciones</h3>

                <div x-data="{ open: false }" class="relative">
                    <button type="button" @click="open = !open"
                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-md border border-input text-xs font-medium hover:bg-accent">
                        + Agregar
                    </button>
                    <div x-show="open" @click.away="open = false" x-cloak
                         class="absolute right-0 z-20 mt-1 w-56 rounded-md border border-border bg-background shadow-lg py-1">
                        @foreach($this->availableTypes as $type => $label)
                            <button type="button"
                                    wire:click="addSection('{{ $type }}')"
                                    @click="open = false"
                                    class="block w-full text-left px-3 py-2 text-sm hover:bg-accent">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            <ul class="divide-y divide-border">
                @forelse($this->sections as $index => $section)
                    <li wire:key="section-{{ $section->id }}"
                        class="flex items-center gap-2 px-3 py-2.5 {{ $selectedId === $section->id ? 'bg-accent/50' : '' }}">

                        <div class="flex flex-col">
                            <button type="button" wire:click="move({{ $section->id }}, 'up')"
                                    @disabled($index === 0)
                                    class="px-1 text-muted-foreground hover:text-foreground disabled:opacity-30"
                                    title="Subir">▲</button>
                            <button type="button" wire:click="move({{ $section->id }}, 'down')"
                                    @disabled($index === $this->sections->count() - 1)
                                    class="px-1 text-muted-foreground hover:text-foreground disabled:opacity-30"
                                    title="Bajar">▼</button>
                        </div>

                        <button type="button" wire:click="select({{ $section->id }})" class="flex-1 text-left min-w-0">
                            <span class="block text-sm font-medium text-foreground truncate">
                                {{ $section->data['heading'] ?? \App\Shop\Landing\SectionTypes::label($section->type) }}
                            </span>
                            <span class="block text-xs text-muted-foreground">
                                {{ \App\Shop\Landing\SectionTypes::label($section->type) }}
                            </span>
                        </button>

                        <button type="button" wire:click="toggleEnabled({{ $section->id }})"
                                class="px-1.5 text-xs {{ $section->is_enabled ? 'text-foreground' : 'text-muted-foreground line-through' }}"
                                title="{{ $section->is_enabled ? 'Ocultar sección' : 'Mostrar sección' }}">
                            {{ $section->is_enabled ? 'Visible' : 'Oculta' }}
                        </button>

                        <button type="button" wire:click="deleteSection({{ $section->id }})"
                                wire:confirm="¿Eliminar esta sección? También se borran sus imágenes."
                                class="px-1.5 text-muted-foreground hover:text-red-600" title="Eliminar">✕</button>
                    </li>
                @empty
                    <li class="px-4 py-6 text-sm text-muted-foreground">
                        No hay secciones. Agregá una para empezar.
                    </li>
                @endforelse
            </ul>

            @if($this->sections->where('is_enabled', true)->isEmpty() && $landingEnabled)
                <p class="border-t border-border px-4 py-3 text-xs text-amber-600 dark:text-amber-400">
                    No hay secciones visibles: /tienda mostrará una presentación mínima.
                </p>
            @endif
        </div>

        {{-- Columna derecha: formulario de la sección seleccionada --}}
        <livewire:settings.landing-section-form />
    </div>
</div>
```

- [ ] **Step 5: Correr — debe pasar**

Run: `php artisan test --filter LandingEditorTest`
Expected: PASS (9 tests). Nota: el blade referencia `<livewire:settings.landing-section-form />`, que se crea en Task 6 — **hasta entonces el render del componente falla**. Para mantener este task verde, crear ya un stub mínimo:

`app/Livewire/Settings/LandingSectionForm.php`:
```php
<?php

namespace App\Livewire\Settings;

use Livewire\Component;

class LandingSectionForm extends Component
{
    public function render()
    {
        return view('livewire.settings.landing-section-form');
    }
}
```
`resources/views/livewire/settings/landing-section-form.blade.php`:
```blade
<div class="rounded-lg border border-border bg-background p-4">
    <p class="text-sm text-muted-foreground">Elegí una sección de la izquierda para editarla.</p>
</div>
```

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Settings/LandingEditor.php app/Livewire/Settings/LandingSectionForm.php resources/views/livewire/settings/landing-editor.blade.php resources/views/livewire/settings/landing-section-form.blade.php tests/Feature/Settings/LandingEditorTest.php
git commit -m "feat(settings): editor de landing con orden, alta/baja y publicacion"
```

---

## Task 5: Partials de formulario de los 7 tipos + test del contrato

**Files:**
- Create: `resources/views/settings/landing/forms/{hero,about,hours,categories,gallery,contact,cta}.blade.php`
- Test: `tests/Feature/Settings/SectionTypesContractTest.php`

Cada partial edita `$form` (array del componente `LandingSectionForm`) vía `wire:model`. Los campos de
imagen y el editor de texto rico se cablean en Tasks 6 y 7; acá van los campos de texto y la estructura.

- [ ] **Step 1: Escribir el test del contrato (falla)**

`tests/Feature/Settings/SectionTypesContractTest.php`:
```php
<?php

namespace Tests\Feature\Settings;

use App\Shop\Landing\SectionTypes;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Blinda el contrato de escalabilidad: un tipo declarado en el registry DEBE tener
 * su partial de render (tienda) y su partial de formulario (editor). Si alguien
 * agrega un tipo a medias, esto falla acá y no en producción.
 */
class SectionTypesContractTest extends TestCase
{
    public function test_every_type_has_render_and_form_partials(): void
    {
        foreach (SectionTypes::keys() as $type) {
            $this->assertTrue(
                View::exists(SectionTypes::partial($type)),
                "Falta el partial de render del tipo {$type}: " . SectionTypes::partial($type)
            );
            $this->assertTrue(
                View::exists(SectionTypes::form($type)),
                "Falta el partial de formulario del tipo {$type}: " . SectionTypes::form($type)
            );
        }
    }
}
```

- [ ] **Step 2: Correr — debe fallar**

Run: `php artisan test --filter SectionTypesContractTest`
Expected: FAIL — faltan los 7 partials de formulario.

- [ ] **Step 3: `hero`**

`resources/views/settings/landing/forms/hero.blade.php`:
```blade
<div class="space-y-4">
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Título</label>
        <input type="text" wire:model="form.heading" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.heading') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Subtítulo</label>
        <input type="text" wire:model="form.subheading" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.subheading') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div class="grid sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-foreground mb-1">Texto del botón</label>
            <input type="text" wire:model="form.cta_text" class="w-full rounded-md border-input bg-background text-sm">
            @error('form.cta_text') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-foreground mb-1">El botón lleva a</label>
            @include('settings.landing.forms.partials.target', ['field' => 'form.cta_target'])
        </div>
    </div>

    @include('settings.landing.forms.partials.single-image', [
        'field' => 'background_image_path',
        'label' => 'Imagen de fondo',
        'help' => 'Opcional. Si no cargás ninguna, se usa el degradado con los colores de la tienda.',
    ])
</div>
```

- [ ] **Step 4: Los dos partials compartidos**

`resources/views/settings/landing/forms/partials/target.blade.php`:
```blade
{{-- Selector de destino: catálogo / WhatsApp / URL propia. $field = 'form.cta_target' o 'form.target' --}}
@php($current = data_get($this, $field))
<select wire:model.live="{{ $field }}" class="w-full rounded-md border-input bg-background text-sm">
    <option value="catalog">El catálogo de la tienda</option>
    <option value="whatsapp">WhatsApp del negocio</option>
    <option value="{{ in_array($current, ['catalog', 'whatsapp'], true) ? '' : $current }}">Otra dirección…</option>
</select>

@if(! in_array($current, ['catalog', 'whatsapp'], true))
    <input type="text" wire:model="{{ $field }}" placeholder="https://… o /pagina"
           class="mt-2 w-full rounded-md border-input bg-background text-sm">
@endif
@error($field) <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
```

`resources/views/settings/landing/forms/partials/single-image.blade.php`:
```blade
{{-- Imagen única. $field = clave dentro de $form (ej. 'background_image_path'), $label, $help --}}
<div>
    <label class="block text-sm font-medium text-foreground mb-1">{{ $label }}</label>
    @if($help)<p class="text-xs text-muted-foreground mb-2">{{ $help }}</p>@endif

    @if(! empty($form[$field]))
        <div class="flex items-center gap-3 mb-2">
            <img src="{{ \Illuminate\Support\Facades\Storage::url($form[$field]) }}" alt=""
                 class="h-16 w-16 rounded-md object-cover border border-border">
            <x-secondary-button type="button" wire:click="removeImage('{{ $field }}')">Quitar</x-secondary-button>
        </div>
    @endif

    <input type="file" wire:model="imageUpload.{{ $field }}" accept="image/png,image/jpeg,image/webp"
           class="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border file:border-input file:bg-background file:px-3 file:py-1.5 file:text-sm">
    <div wire:loading wire:target="imageUpload.{{ $field }}" class="text-xs text-blue-600 mt-1">Subiendo…</div>
    @error('imageUpload.' . $field) <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
</div>
```

- [ ] **Step 5: `about`**

`resources/views/settings/landing/forms/about.blade.php`:
```blade
<div class="space-y-4">
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Título</label>
        <input type="text" wire:model="form.heading" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.heading') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Texto</label>
        {{-- El editor de texto rico se monta en Task 7. Por ahora, textarea con el HTML. --}}
        <textarea wire:model="form.body_html" rows="8"
                  class="w-full rounded-md border-input bg-background text-sm font-mono"></textarea>
        @error('form.body_html') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    @include('settings.landing.forms.partials.single-image', [
        'field' => 'image_path',
        'label' => 'Imagen',
        'help' => 'Opcional. Se muestra al costado del texto.',
    ])
</div>
```

- [ ] **Step 6: `hours`**

`resources/views/settings/landing/forms/hours.blade.php`:
```blade
<div class="space-y-4">
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Título</label>
        <input type="text" wire:model="form.heading" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.heading') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div class="space-y-2">
        <label class="block text-sm font-medium text-foreground">Horarios</label>
        @foreach(($form['rows'] ?? []) as $i => $row)
            <div class="flex items-center gap-2" wire:key="hours-row-{{ $i }}">
                <input type="text" wire:model="form.rows.{{ $i }}.label" placeholder="Lunes a Viernes"
                       class="flex-1 rounded-md border-input bg-background text-sm">
                <input type="text" wire:model="form.rows.{{ $i }}.value" placeholder="9:00 – 18:00"
                       class="flex-1 rounded-md border-input bg-background text-sm">
                <button type="button" wire:click="removeRow('rows', {{ $i }})"
                        class="px-2 text-muted-foreground hover:text-red-600" title="Quitar">✕</button>
            </div>
            @error('form.rows.' . $i . '.label') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            @error('form.rows.' . $i . '.value') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
        @endforeach

        <x-secondary-button type="button" wire:click="addRow('rows', {'label': '', 'value': ''})">
            + Agregar horario
        </x-secondary-button>
    </div>
</div>
```

NOTA para el implementador: `wire:click` con un objeto literal JS no es válido en Livewire. Usar en su
lugar un método sin argumentos por tipo de fila:
```blade
        <x-secondary-button type="button" wire:click="addHoursRow">+ Agregar horario</x-secondary-button>
```
y definir `addHoursRow()` en el componente (Task 6). Lo mismo para `categories` con `addCategoryItem()`.

- [ ] **Step 7: `categories`**

`resources/views/settings/landing/forms/categories.blade.php`:
```blade
<div class="space-y-4">
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Título</label>
        <input type="text" wire:model="form.heading" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.heading') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Qué mostrar</label>
        <select wire:model.live="form.source" class="w-full rounded-md border-input bg-background text-sm">
            <option value="auto">Las categorías con productos publicados (automático)</option>
            <option value="manual">Una lista que escribo yo</option>
        </select>
        @error('form.source') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    @if(($form['source'] ?? 'auto') === 'manual')
        <div class="space-y-2">
            <label class="block text-sm font-medium text-foreground">Elementos</label>
            @foreach(($form['items'] ?? []) as $i => $item)
                <div class="flex items-center gap-2" wire:key="cat-item-{{ $i }}">
                    <input type="text" wire:model="form.items.{{ $i }}.label" placeholder="Nombre"
                           class="flex-1 rounded-md border-input bg-background text-sm">
                    <input type="text" wire:model="form.items.{{ $i }}.link" placeholder="https://… o /pagina"
                           class="flex-1 rounded-md border-input bg-background text-sm">
                    <button type="button" wire:click="removeRow('items', {{ $i }})"
                            class="px-2 text-muted-foreground hover:text-red-600" title="Quitar">✕</button>
                </div>
                @error('form.items.' . $i . '.label') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                @error('form.items.' . $i . '.link') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            @endforeach

            <x-secondary-button type="button" wire:click="addCategoryItem">+ Agregar elemento</x-secondary-button>
        </div>
    @endif
</div>
```

- [ ] **Step 8: `gallery`**

`resources/views/settings/landing/forms/gallery.blade.php`:
```blade
<div class="space-y-4">
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Título</label>
        <input type="text" wire:model="form.heading" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.heading') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-medium text-foreground mb-2">Fotos</label>
        @if(! empty($form['images']))
            <div class="grid grid-cols-3 sm:grid-cols-4 gap-2 mb-3">
                @foreach($form['images'] as $i => $img)
                    <div class="relative" wire:key="gal-{{ $i }}">
                        <img src="{{ \Illuminate\Support\Facades\Storage::url($img) }}" alt=""
                             class="aspect-square w-full rounded-md object-cover border border-border">
                        <button type="button" wire:click="removeGalleryImage({{ $i }})"
                                class="absolute top-1 right-1 rounded-full bg-background/90 border border-border px-1.5 text-xs hover:text-red-600"
                                title="Quitar">✕</button>
                    </div>
                @endforeach
            </div>
        @endif

        <input type="file" wire:model="galleryUpload" accept="image/png,image/jpeg,image/webp"
               class="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border file:border-input file:bg-background file:px-3 file:py-1.5 file:text-sm">
        <div wire:loading wire:target="galleryUpload" class="text-xs text-blue-600 mt-1">Subiendo…</div>
        @error('galleryUpload') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>
</div>
```

- [ ] **Step 9: `contact`**

`resources/views/settings/landing/forms/contact.blade.php`:
```blade
<div class="space-y-4">
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Título</label>
        <input type="text" wire:model="form.heading" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.heading') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">WhatsApp</label>
        <input type="text" wire:model="form.whatsapp" placeholder="+591 700 12345"
               class="w-full rounded-md border-input bg-background text-sm">
        @error('form.whatsapp') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Dirección</label>
        <input type="text" wire:model="form.address" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.address') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Correo</label>
        <input type="email" wire:model="form.email" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.email') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>
</div>
```

- [ ] **Step 10: `cta`**

`resources/views/settings/landing/forms/cta.blade.php`:
```blade
<div class="space-y-4">
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Título</label>
        <input type="text" wire:model="form.heading" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.heading') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Texto</label>
        <input type="text" wire:model="form.text" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.text') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>
    <div class="grid sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-foreground mb-1">Texto del botón</label>
            <input type="text" wire:model="form.button_text" class="w-full rounded-md border-input bg-background text-sm">
            @error('form.button_text') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-foreground mb-1">El botón lleva a</label>
            @include('settings.landing.forms.partials.target', ['field' => 'form.target'])
        </div>
    </div>
</div>
```

- [ ] **Step 11: Correr — debe pasar**

Run: `php artisan test --filter SectionTypesContractTest`
Expected: PASS.

- [ ] **Step 12: Commit**

```bash
git add resources/views/settings/landing/forms tests/Feature/Settings/SectionTypesContractTest.php
git commit -m "feat(settings): formularios de los 7 tipos de seccion + test del contrato"
```

---

## Task 6: `LandingSectionForm` (cargar, validar, sanear, guardar, imágenes)

**Files:**
- Modify: `app/Livewire/Settings/LandingSectionForm.php`
- Modify: `resources/views/livewire/settings/landing-section-form.blade.php`
- Test: `tests/Feature/Settings/LandingSectionFormTest.php`

- [ ] **Step 1: Escribir el test que falla**

`tests/Feature/Settings/LandingSectionFormTest.php`:
```php
<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\LandingSectionForm;
use App\Shop\Models\LandingSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class LandingSectionFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        LandingSection::query()->delete();
    }

    private function section(string $type, array $data = []): LandingSection
    {
        return LandingSection::create([
            'type' => $type,
            'sort_order' => 0,
            'is_enabled' => true,
            'data' => $data,
        ]);
    }

    public function test_loads_the_selected_section_merged_with_defaults(): void
    {
        $s = $this->section('hero', ['heading' => 'Hola']);

        Livewire::test(LandingSectionForm::class)
            ->call('load', $s->id)
            ->assertSet('type', 'hero')
            ->assertSet('form.heading', 'Hola')
            ->assertSet('form.cta_target', 'catalog'); // viene del default del registry
    }

    public function test_save_persists_the_data(): void
    {
        $s = $this->section('hero', ['heading' => 'Viejo']);

        Livewire::test(LandingSectionForm::class)
            ->call('load', $s->id)
            ->set('form.heading', 'Nuevo')
            ->call('save')
            ->assertDispatched('landing-sections-changed');

        $this->assertSame('Nuevo', $s->fresh()->data['heading']);
    }

    public function test_save_sanitizes_rich_html(): void
    {
        $s = $this->section('about', ['heading' => 'H']);

        Livewire::test(LandingSectionForm::class)
            ->call('load', $s->id)
            ->set('form.body_html', '<p>Ok</p><script>alert(1)</script>')
            ->call('save');

        $this->assertStringNotContainsString('<script', $s->fresh()->data['body_html']);
        $this->assertStringContainsString('Ok', $s->fresh()->data['body_html']);
    }

    public function test_save_rejects_javascript_url_in_target(): void
    {
        $s = $this->section('cta', ['button_text' => 'Ir']);

        Livewire::test(LandingSectionForm::class)
            ->call('load', $s->id)
            ->set('form.target', 'javascript:alert(1)')
            ->call('save')
            ->assertHasErrors('form.target');

        $this->assertNotSame('javascript:alert(1)', $s->fresh()->data['target'] ?? null);
    }

    public function test_save_accepts_catalog_whatsapp_and_valid_urls(): void
    {
        $s = $this->section('cta', ['button_text' => 'Ir']);

        foreach (['catalog', 'whatsapp', 'https://example.com', '/pagina'] as $value) {
            Livewire::test(LandingSectionForm::class)
                ->call('load', $s->id)
                ->set('form.target', $value)
                ->call('save')
                ->assertHasNoErrors();
        }
    }

    public function test_save_requires_hero_heading(): void
    {
        $s = $this->section('hero', ['heading' => 'X']);

        Livewire::test(LandingSectionForm::class)
            ->call('load', $s->id)
            ->set('form.heading', '')
            ->call('save')
            ->assertHasErrors('form.heading');
    }

    public function test_uploading_an_image_stores_it_and_sets_the_path(): void
    {
        $s = $this->section('about', []);

        $component = Livewire::test(LandingSectionForm::class)
            ->call('load', $s->id)
            ->set('imageUpload.image_path', UploadedFile::fake()->image('foto.jpg'));

        $path = $component->get('form.image_path');
        $this->assertNotEmpty($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_removing_an_image_deletes_the_file(): void
    {
        $s = $this->section('about', []);

        $component = Livewire::test(LandingSectionForm::class)
            ->call('load', $s->id)
            ->set('imageUpload.image_path', UploadedFile::fake()->image('foto.jpg'));

        $path = $component->get('form.image_path');
        $component->call('removeImage', 'image_path');

        Storage::disk('public')->assertMissing($path);
        $this->assertEmpty($component->get('form.image_path'));
    }

    public function test_gallery_upload_appends_to_images(): void
    {
        $s = $this->section('gallery', ['images' => []]);

        $component = Livewire::test(LandingSectionForm::class)
            ->call('load', $s->id)
            ->set('galleryUpload', UploadedFile::fake()->image('a.jpg'))
            ->set('galleryUpload', UploadedFile::fake()->image('b.jpg'));

        $this->assertCount(2, $component->get('form.images'));
    }

    public function test_add_and_remove_hours_rows(): void
    {
        $s = $this->section('hours', ['rows' => []]);

        Livewire::test(LandingSectionForm::class)
            ->call('load', $s->id)
            ->call('addHoursRow')
            ->assertCount('form.rows', 1)
            ->call('removeRow', 'rows', 0)
            ->assertCount('form.rows', 0);
    }
}
```

- [ ] **Step 2: Correr — debe fallar**

Run: `php artisan test --filter LandingSectionFormTest`
Expected: FAIL — métodos inexistentes en el stub.

- [ ] **Step 3: Implementar el componente**

`app/Livewire/Settings/LandingSectionForm.php` (reemplaza el stub de Task 4):
```php
<?php

namespace App\Livewire\Settings;

use App\Shop\Landing\LandingImages;
use App\Shop\Landing\LandingUrl;
use App\Shop\Landing\SectionTypes;
use App\Shop\Models\LandingSection;
use App\Shop\Services\LandingHtmlSanitizer;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Contenido de UNA sección: campos, validación, saneo e imágenes.
 * No sabe nada del orden ni del resto de la lista — de eso se ocupa LandingEditor.
 */
class LandingSectionForm extends Component
{
    use WithFileUploads;

    public ?int $sectionId = null;

    public string $type = '';

    /** @var array<string,mixed> copia editable del `data` de la sección */
    public array $form = [];

    /** @var array<string,mixed> subidas de imagen única, keyed por campo del form */
    public array $imageUpload = [];

    public $galleryUpload;

    /** Campos cuyo valor es un destino de enlace ('catalog' | 'whatsapp' | URL). */
    private const TARGET_FIELDS = ['cta_target', 'target'];

    #[On('landing-section-selected')]
    public function load(int $id): void
    {
        $section = LandingSection::find($id);
        if (! $section) {
            return;
        }

        $this->resetValidation();
        $this->sectionId = $section->id;
        $this->type = $section->type;
        $this->form = array_merge(SectionTypes::defaultData($section->type), $section->data ?? []);
        $this->imageUpload = [];
        $this->galleryUpload = null;
    }

    #[On('landing-section-cleared')]
    public function clear(): void
    {
        $this->reset(['sectionId', 'type', 'form', 'imageUpload', 'galleryUpload']);
        $this->resetValidation();
    }

    public function addHoursRow(): void
    {
        $this->form['rows'][] = ['label' => '', 'value' => ''];
    }

    public function addCategoryItem(): void
    {
        $this->form['items'][] = ['label' => '', 'link' => ''];
    }

    public function removeRow(string $key, int $index): void
    {
        unset($this->form[$key][$index]);
        $this->form[$key] = array_values($this->form[$key] ?? []);
    }

    public function updatedImageUpload(): void
    {
        foreach ($this->imageUpload as $field => $file) {
            if (! $file) {
                continue;
            }

            $this->validate(["imageUpload.{$field}" => ['image', 'max:2048', 'mimes:png,jpg,jpeg,webp']]);

            app(LandingImages::class)->delete($this->form[$field] ?? null);
            $this->form[$field] = app(LandingImages::class)->store($file);
            $this->imageUpload[$field] = null;
        }
    }

    public function updatedGalleryUpload(): void
    {
        if (! $this->galleryUpload) {
            return;
        }

        $this->validate(['galleryUpload' => ['image', 'max:2048', 'mimes:png,jpg,jpeg,webp']]);

        $this->form['images'][] = app(LandingImages::class)->store($this->galleryUpload);
        $this->galleryUpload = null;
    }

    public function removeImage(string $field): void
    {
        app(LandingImages::class)->delete($this->form[$field] ?? null);
        $this->form[$field] = null;
    }

    public function removeGalleryImage(int $index): void
    {
        app(LandingImages::class)->delete($this->form['images'][$index] ?? null);
        unset($this->form['images'][$index]);
        $this->form['images'] = array_values($this->form['images'] ?? []);
    }

    public function save(): void
    {
        if (! $this->sectionId) {
            return;
        }

        $rules = [];
        foreach (SectionTypes::rules($this->type) as $field => $rule) {
            $rules["form.{$field}"] = $rule;
        }

        // Los destinos de enlace: o una palabra clave, o una URL segura. Se valida
        // (no se reescribe en silencio) para que el usuario vea qué está mal.
        foreach (self::TARGET_FIELDS as $field) {
            if (! array_key_exists($field, $this->form)) {
                continue;
            }
            $rules["form.{$field}"][] = function (string $attribute, $value, $fail) {
                if ($value === null || $value === '' || in_array($value, ['catalog', 'whatsapp'], true)) {
                    return;
                }
                if (! LandingUrl::isSafeUrl($value)) {
                    $fail('El enlace debe empezar con http://, https:// o /');
                }
            };
        }

        foreach (array_keys($this->form['items'] ?? []) as $i) {
            $rules["form.items.{$i}.link"][] = function (string $attribute, $value, $fail) {
                if ($value === null || $value === '') {
                    return;
                }
                if (! LandingUrl::isSafeUrl($value)) {
                    $fail('El enlace debe empezar con http://, https:// o /');
                }
            };
        }

        $this->validate($rules);

        $data = $this->form;

        // Invariante de SP1: el HTML rico se guarda ya saneado (y el render lo sanea igual).
        if (array_key_exists('body_html', $data)) {
            $data['body_html'] = app(LandingHtmlSanitizer::class)->sanitize($data['body_html']);
        }

        // Las rutas de imagen se validan antes de persistir: el héroe las interpola en CSS.
        foreach (['background_image_path', 'image_path'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = LandingUrl::safeStoragePath($data[$field]);
            }
        }

        LandingSection::whereKey($this->sectionId)->update(['data' => $data]);

        $this->dispatch('landing-sections-changed');
        $this->dispatch('landing-saved');
    }

    public function render()
    {
        return view('livewire.settings.landing-section-form');
    }
}
```

NOTA: `$rules["form.{$field}"][] = …` asume que la regla ya es un array. Todas las `rules` del registry
se declaran como arrays (Task 1), así que es seguro; si un campo de destino no estuviera en las reglas,
inicializarlo con `$rules["form.{$field}"] ??= ['nullable', 'string', 'max:255'];` antes de anexar.

- [ ] **Step 4: Implementar el blade**

`resources/views/livewire/settings/landing-section-form.blade.php`:
```blade
<div class="rounded-lg border border-border bg-background">
    @if(! $sectionId)
        <div class="p-8 text-center">
            <p class="text-sm text-muted-foreground">Elegí una sección de la izquierda para editarla.</p>
        </div>
    @else
        <div class="flex items-center justify-between border-b border-border px-4 py-3">
            <h3 class="text-sm font-semibold text-foreground">
                Editando: {{ \App\Shop\Landing\SectionTypes::label($type) }}
            </h3>
            <div class="flex items-center gap-2">
                <span wire:loading wire:target="save" class="text-xs text-muted-foreground">Guardando…</span>
                <x-primary-button type="button" wire:click="save">Guardar</x-primary-button>
            </div>
        </div>

        <div class="p-4">
            @include(\App\Shop\Landing\SectionTypes::form($type))
        </div>
    @endif
</div>
```

- [ ] **Step 5: Correr — debe pasar**

Run: `php artisan test --filter LandingSectionFormTest`
Expected: PASS (11 tests).

- [ ] **Step 6: Correr también los tests del editor (no deben romperse)**

Run: `php artisan test --filter "LandingEditorTest|SectionTypesContractTest|LandingEditorAccessTest"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Settings/LandingSectionForm.php resources/views/livewire/settings/landing-section-form.blade.php tests/Feature/Settings/LandingSectionFormTest.php
git commit -m "feat(settings): formulario de seccion con validacion, saneo e imagenes"
```

---

## Task 7: Editor de texto rico (Trix)

**Files:**
- Modify: `package.json` (dependencia `trix`)
- Modify: `resources/js/app.js`
- Modify: `resources/views/settings/landing/forms/about.blade.php`

- [ ] **Step 1: Instalar Trix**

Run: `npm install trix`
Expected: `trix` en `dependencies` de `package.json`.

- [ ] **Step 2: Importarlo en el bundle admin**

En `resources/js/app.js`, agregar junto a los otros imports (arriba del todo):
```js
import "trix";
import "trix/dist/trix.css";
```
Solo va en `app.js` (bundle admin). **No** tocar `resources/js/shop/shop.js`: la tienda pública no
necesita el editor.

- [ ] **Step 3: Reemplazar el textarea por Trix en el formulario `about`**

En `resources/views/settings/landing/forms/about.blade.php`, cambiar el bloque del textarea por:
```blade
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Texto</label>

        {{-- wire:ignore es obligatorio: sin él, el re-render de Livewire le pisa el DOM a Trix.
             El wire:key con el id de la sección fuerza a recrear el editor al cambiar de sección. --}}
        <div wire:ignore wire:key="trix-{{ $sectionId }}">
            <input id="body-html-{{ $sectionId }}" type="hidden" value="{{ $form['body_html'] ?? '' }}">
            <trix-editor
                input="body-html-{{ $sectionId }}"
                x-data
                x-on:trix-change="$wire.set('form.body_html', $event.target.value, false)"
                class="trix-content rounded-md border border-input bg-background text-sm"></trix-editor>
        </div>

        @error('form.body_html') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        <p class="text-xs text-muted-foreground mt-1">
            El formato se limpia al guardar: se permiten negrita, cursiva, listas, títulos y enlaces.
        </p>
    </div>
```

Notas para el implementador:
- Alpine viene con Livewire 3 (en `app.js` el import de Alpine está comentado a propósito) — `x-data`
  y `$wire` funcionan sin instalar nada.
- El tercer argumento `false` de `$wire.set` evita un re-render por cada tecla.
- Trix inserta su propia barra de herramientas; los tags que genera (h1, strong, em, ul, ol, a…) están
  cubiertos por el allowlist del sanitizer, salvo `h1`, que el sanitizer degrada — es el comportamiento
  buscado (el h1 de la página es el del héroe).

- [ ] **Step 4: Compilar y verificar que el bundle sale bien**

Run: `npm run build`
Expected: build sin errores; `trix` aparece en el output de assets.

- [ ] **Step 5: Verificación manual (no hay test automático de un editor JS)**

Levantar la app, entrar como admin a `settings/tienda/landing`, seleccionar la sección "Quiénes somos"
y confirmar: (a) aparece la barra de Trix, (b) escribir en negrita y guardar persiste `<strong>`,
(c) cambiar a otra sección y volver recarga el contenido correcto, (d) pegar
`<script>alert(1)</script>` desde HTML y guardar NO lo persiste.
Registrar el resultado en el reporte.

- [ ] **Step 6: Correr los tests del formulario (el saneo sigue cubierto)**

Run: `php artisan test --filter LandingSectionFormTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add package.json package-lock.json resources/js/app.js resources/views/settings/landing/forms/about.blade.php
git commit -m "feat(settings): editor de texto rico Trix en la seccion Acerca de"
```

---

## Task 8: Enlace desde Ajustes + seeder unificado con el registry

**Files:**
- Modify: `resources/views/livewire/settings/setting-groups.blade.php`
- Modify: `database/seeders/DefaultLandingTemplateSeeder.php`
- Test: `tests/Feature/Settings/LandingEditorAccessTest.php` (agregar), `tests/Feature/Shop/ShopLandingRoutingTest.php` (agregar)

- [ ] **Step 1: Escribir los tests que fallan**

Agregar a `tests/Feature/Settings/LandingEditorAccessTest.php`:
```php
    public function test_settings_page_links_to_the_landing_editor_for_admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee(route('settings.shop-landing'), false);
    }
```

Agregar a `tests/Feature/Shop/ShopLandingRoutingTest.php` (regresión de ida y vuelta editor → tienda):
```php
    public function test_content_saved_by_the_editor_shows_on_the_public_landing(): void
    {
        \App\Models\Setting::set('shop_landing_enabled', '1');

        $section = LandingSection::create([
            'type' => 'about',
            'sort_order' => 0,
            'is_enabled' => true,
            'data' => ['heading' => 'MARCA_IDA_VUELTA', 'body_html' => '<p>Texto guardado</p>'],
        ]);

        \Livewire\Livewire::test(\App\Livewire\Settings\LandingSectionForm::class)
            ->call('load', $section->id)
            ->set('form.heading', 'MARCA_EDITADA')
            ->call('save');

        $this->get('/tienda')->assertOk()->assertSee('MARCA_EDITADA');
    }
```

Y agregar al test del seeder por defecto (mismo archivo) la verificación de que usa el registry:
```php
    public function test_default_template_uses_registry_defaults(): void
    {
        \App\Shop\Models\LandingSection::query()->delete();
        (new \Database\Seeders\DefaultLandingTemplateSeeder())->run();

        $hero = \App\Shop\Models\LandingSection::where('type', 'hero')->firstOrFail();

        $this->assertSame(
            \App\Shop\Landing\SectionTypes::defaultData('hero')['subheading'],
            $hero->data['subheading']
        );
    }
```

- [ ] **Step 2: Correr — deben fallar**

Run: `php artisan test --filter "LandingEditorAccessTest|ShopLandingRoutingTest"`
Expected: FAIL — falta el enlace y el seeder aún hardcodea su copy.

- [ ] **Step 3: Agregar el botón en el panel Tienda**

En `resources/views/livewire/settings/setting-groups.blade.php`, dentro del bloque
`@if($group['key'] === 'tienda')` de "Personalización visual" (el que empieza cerca de la línea 152),
agregar al final de ese `<div class="border-b border-border px-4 py-5 space-y-6 …">`:
```blade
                    @can('shop.landing.manage')
                        <div class="border-t border-border pt-4">
                            <h4 class="text-sm font-semibold text-foreground mb-1">Página de presentación</h4>
                            <p class="text-xs text-muted-foreground mb-2">
                                Lo que ven los visitantes al entrar a /tienda: presentación, horarios, quiénes somos y el botón al catálogo.
                            </p>
                            <a href="{{ route('settings.shop-landing') }}"
                               class="inline-flex items-center gap-2 px-3 py-2 rounded-md border border-input bg-background text-sm font-medium hover:bg-accent">
                                Editar landing →
                            </a>
                        </div>
                    @endcan
```

- [ ] **Step 4: Unificar el seeder con el registry**

En `database/seeders/DefaultLandingTemplateSeeder.php`, reemplazar el array `$sections` hardcodeado
por los defaults del registry, conservando el orden y los overrides de copy que sí son propios de la
plantilla (el texto de bienvenida de `about`):
```php
        // El registry es la fuente de la copy por defecto (evita que editor y plantilla diverjan).
        $template = [
            'hero' => [],
            'about' => [
                'body_html' => '<p>Somos un negocio comprometido con ofrecerte los mejores productos y atención. Edita este texto desde Ajustes.</p>',
            ],
            'hours' => [],
            'categories' => [],
            'cta' => [],
        ];

        $order = 0;
        foreach ($template as $type => $overrides) {
            LandingSection::create([
                'type' => $type,
                'sort_order' => $order++,
                'is_enabled' => true,
                'data' => array_merge(SectionTypes::defaultData($type), $overrides),
            ]);
        }
```
Agregar el import `use App\Shop\Landing\SectionTypes;` al principio del archivo. El resto del seeder
(los dos guards de idempotencia y el seteo del flag) queda igual.

- [ ] **Step 5: Correr — deben pasar**

Run: `php artisan test --filter "LandingEditorAccessTest|ShopLandingRoutingTest|LandingEditorTest"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/views/livewire/settings/setting-groups.blade.php database/seeders/DefaultLandingTemplateSeeder.php tests/Feature/Settings/LandingEditorAccessTest.php tests/Feature/Shop/ShopLandingRoutingTest.php
git commit -m "feat(settings): enlace al editor desde Ajustes; plantilla usa defaults del registry"
```

---

## Cierre

- [ ] **Suite completa**

Run: `php artisan test`
Expected: PASS, sin regresiones. Tarda ~10 min. Prestar atención a los tests de SP1
(`ShopLandingRoutingTest`, `LandingSectionTest`) y a cualquier test de permisos que cuente el total
de permisos del rol admin (el permiso nuevo puede correr un conteo esperado).

- [ ] **Build de assets**

Run: `npm run build`
Expected: sin errores.

- [ ] **Nota de deploy**

`php artisan migrate` (aditiva, **NUNCA** `:fresh`) crea el permiso y lo asigna a admin+developer.
Luego `php artisan cache:clear` y `npm run build` en el servidor (Trix entra al bundle admin).

Al terminar todas las tasks → **superpowers:finishing-a-development-branch**.

---

## Self-review (checklist del autor)

- **Cobertura de spec:** R1 (Task 1), R2 (Task 2), R3 (Task 2), R4 (Task 4), R5 (Task 6), R6 (Tasks 4+6),
  R7 (Task 5), R8 (Task 7), R9 (Task 6), R10 (Task 6, vía `isSafeUrl` + regla de validación),
  R11 (Tasks 3+6), R12 (Task 6), R13 (Tasks 3+4+6), R14 (Task 8), R15 (Task 8). ✔
- **Sin placeholders:** todo el código va inline y completo. ✔
- **Consistencia de tipos:** `SectionTypes::{keys,exists,label,partial,form,rules,defaultData}` usados
  igual en componentes, blades, seeder y tests. `LandingImages::{store,delete,deleteForSection}` firma
  única. Eventos: `landing-section-selected` (editor→form), `landing-section-cleared` (editor→form),
  `landing-sections-changed` (form→editor). ✔
- **Riesgos anotados en el propio plan:** (a) el blade del editor referencia el componente del
  formulario antes de que exista → Task 4 crea un stub; (b) `wire:click` no acepta objetos literales JS
  → métodos `addHoursRow`/`addCategoryItem`; (c) `LandingUrl::safeUrl()` resuelve una ruta que puede no
  existir en admin → por eso Task 1 agrega `isSafeUrl()` y el guardado valida en vez de reescribir. ✔
