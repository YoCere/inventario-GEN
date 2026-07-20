# Landing configurable de la tienda — Plan SP1

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que `/tienda` muestre una landing configurable por secciones (con plantilla por defecto sembrada), moviendo el catálogo a `/tienda/catalogo`.

**Architecture:** Tabla `landing_sections` + modelo `LandingSection` (type/sort_order/is_enabled/data-JSON). Un registry `SectionTypes` declara los 7 tipos. `ShopController@index` decide landing vs catálogo por el ajuste `shop_landing_enabled`; el catálogo actual se mueve a `catalog()`/`GET /tienda/catalogo`. La vista `shop.landing` itera secciones e incluye un partial por tipo, con HTML rico saneado por `LandingHtmlSanitizer` (mews/purifier). Una migración aditiva siembra la plantilla por defecto.

**Tech Stack:** Laravel 11, PHP 8.x, Blade, Tailwind, PHPUnit (class-style), MySQL (dev). Nueva dependencia: `mews/purifier` (HTMLPurifier).

**Referencia de spec:** `docs/superpowers/specs/2026-07-08-shop-landing-design.md`

---

## Convenciones del repo (leer antes de empezar)

- **NUNCA `migrate:fresh`** (MySQL dev compartido). Migraciones **aditivas** e idempotentes.
- Tests: clase PHPUnit, `extends Tests\TestCase`, `use RefreshDatabase, EnablesShop;` para flujo shop.
  `EnablesShop::enableShop()` (en `tests/Feature/Shop/EnablesShop.php`) activa `shop_enabled='1'` y carga
  las rutas `/tienda/*` en runtime. Llamar en `setUp()`.
- Comando de tests: `php artisan test --filter <Clase>` (o el archivo). Suite completa: `php artisan test`.
- Settings = `App\Models\Setting::get($key, $default)` / `Setting::set($key, $value)` (cache-forever por clave).
- Layout de la tienda: `@extends('shop.layouts.app')`, `@section('title', ...)`, `@section('content')`.
  Variables CSS de tema disponibles en cualquier vista hija: `var(--shop-primary)`, `var(--shop-secondary)`,
  `var(--shop-accent)`, `var(--shop-text-on-primary)`.
- Módulo Shop: código en `app/Shop/`, vistas en `resources/views/shop/` (namespace `shop`).

---

## File Structure

- Create `app/Shop/Services/LandingHtmlSanitizer.php` — saneo HTML allowlist (wrap de Purifier).
- Create `database/migrations/2026_07_08_150000_create_landing_sections_table.php` — tabla.
- Create `app/Shop/Models/LandingSection.php` — modelo + scopes.
- Create `app/Shop/Landing/SectionTypes.php` — registry de tipos.
- Modify `routes/shop.php` — agregar ruta `catalog`.
- Modify `app/Shop/Http/Controllers/ShopController.php` — `index()` despacha landing/catálogo; `catalog()` = lógica actual.
- Create `resources/views/shop/landing.blade.php` — vista landing (loop de secciones).
- Create `resources/views/shop/landing/sections/{hero,about,hours,categories,gallery,contact,cta}.blade.php` — 7 partials.
- Modify `resources/views/shop/index.blade.php`, `product.blade.php`, `checkout.blade.php` — links de catálogo → `shop.catalog`.
- Create `database/migrations/2026_07_08_150100_seed_default_landing_template.php` — siembra plantilla + flag.
- Create tests: `tests/Feature/Shop/LandingHtmlSanitizerTest.php`, `LandingSectionTest.php`, `SectionTypesTest.php`, `ShopLandingRoutingTest.php`.

---

## Task 1: Sanitizer de HTML (mews/purifier + LandingHtmlSanitizer)

**Files:**
- Install: `mews/purifier`
- Create: `app/Shop/Services/LandingHtmlSanitizer.php`
- Test: `tests/Feature/Shop/LandingHtmlSanitizerTest.php`

- [ ] **Step 1: Instalar la librería**

Run:
```bash
composer require mews/purifier
```
Expected: paquete agregado a `composer.json`, `vendor/mews/purifier` presente. (Auto-discovery registra el service provider; no hace falta publicar config — el servicio pasa su propia config a `Purifier::clean`.)

- [ ] **Step 2: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Shop;

use App\Shop\Services\LandingHtmlSanitizer;
use Tests\TestCase;

class LandingHtmlSanitizerTest extends TestCase
{
    private LandingHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new LandingHtmlSanitizer();
    }

    public function test_removes_script_tags(): void
    {
        $out = $this->sanitizer->sanitize('<p>Hola</p><script>alert(1)</script>');
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringContainsString('Hola', $out);
    }

    public function test_keeps_allowed_formatting_tags(): void
    {
        $out = $this->sanitizer->sanitize('<p>Texto <strong>fuerte</strong> y <em>énfasis</em></p><ul><li>uno</li></ul>');
        $this->assertStringContainsString('<strong>', $out);
        $this->assertStringContainsString('<em>', $out);
        $this->assertStringContainsString('<li>', $out);
    }

    public function test_strips_dangerous_attributes(): void
    {
        $out = $this->sanitizer->sanitize('<a href="javascript:alert(1)" onclick="x()">click</a>');
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringNotContainsString('onclick', $out);
    }

    public function test_null_and_empty_return_empty_string(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize(null));
        $this->assertSame('', $this->sanitizer->sanitize('   '));
    }
}
```

- [ ] **Step 3: Correr el test (debe fallar)**

Run: `php artisan test --filter LandingHtmlSanitizerTest`
Expected: FAIL — `Class "App\Shop\Services\LandingHtmlSanitizer" not found`.

- [ ] **Step 4: Implementar el servicio**

```php
<?php

namespace App\Shop\Services;

use Mews\Purifier\Facades\Purifier;

/**
 * Sanea HTML rico de las secciones de la landing antes de renderizarlo.
 * La landing es pública (sin auth) → el saneo con allowlist es la barrera
 * contra XSS almacenado cuando el editor (SP2) permita pegar/editar HTML.
 */
class LandingHtmlSanitizer
{
    /** Tags/atributos permitidos (formato de texto básico, sin scripts/estilos). */
    private const ALLOWED = 'p,br,strong,b,em,i,u,ul,ol,li,a[href|title|target],h2,h3,h4,blockquote';

    public function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        return Purifier::clean($html, [
            'HTML.Allowed' => self::ALLOWED,
            'HTML.TargetBlank' => true,
            'AutoFormat.RemoveEmpty' => true,
        ]);
    }
}
```

- [ ] **Step 5: Correr el test (debe pasar)**

Run: `php artisan test --filter LandingHtmlSanitizerTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock app/Shop/Services/LandingHtmlSanitizer.php tests/Feature/Shop/LandingHtmlSanitizerTest.php
git commit -m "feat(shop): sanitizer HTML para landing (mews/purifier)"
```

---

## Task 2: Tabla + modelo LandingSection

**Files:**
- Create: `database/migrations/2026_07_08_150000_create_landing_sections_table.php`
- Create: `app/Shop/Models/LandingSection.php`
- Test: `tests/Feature/Shop/LandingSectionTest.php`

- [ ] **Step 1: Crear la migración de tabla**

`database/migrations/2026_07_08_150000_create_landing_sections_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_sections', function (Blueprint $table) {
            $table->id();
            $table->string('type');                       // hero|about|hours|categories|gallery|contact|cta
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->json('data')->nullable();             // payload específico por tipo
            $table->timestamps();

            $table->index(['is_enabled', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_sections');
    }
};
```

- [ ] **Step 2: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Shop;

use App\Shop\Models\LandingSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingSectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_data_is_cast_to_array(): void
    {
        $s = LandingSection::create([
            'type' => 'hero',
            'sort_order' => 0,
            'is_enabled' => true,
            'data' => ['heading' => 'Bienvenido'],
        ]);

        $this->assertIsArray($s->fresh()->data);
        $this->assertSame('Bienvenido', $s->fresh()->data['heading']);
    }

    public function test_ordered_scope_sorts_by_sort_order_then_id(): void
    {
        LandingSection::create(['type' => 'cta', 'sort_order' => 2, 'is_enabled' => true, 'data' => []]);
        LandingSection::create(['type' => 'hero', 'sort_order' => 1, 'is_enabled' => true, 'data' => []]);

        $types = LandingSection::ordered()->pluck('type')->all();
        $this->assertSame(['hero', 'cta'], $types);
    }

    public function test_enabled_scope_excludes_disabled(): void
    {
        LandingSection::create(['type' => 'hero', 'sort_order' => 0, 'is_enabled' => true, 'data' => []]);
        LandingSection::create(['type' => 'about', 'sort_order' => 1, 'is_enabled' => false, 'data' => []]);

        $this->assertSame(1, LandingSection::enabled()->count());
    }
}
```

- [ ] **Step 3: Correr el test (debe fallar)**

Run: `php artisan test --filter LandingSectionTest`
Expected: FAIL — `Class "App\Shop\Models\LandingSection" not found`.

- [ ] **Step 4: Implementar el modelo**

`app/Shop/Models/LandingSection.php`:
```php
<?php

namespace App\Shop\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LandingSection extends Model
{
    protected $fillable = ['type', 'sort_order', 'is_enabled', 'data'];

    protected $casts = [
        'is_enabled' => 'boolean',
        'data' => 'array',
    ];

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
```

- [ ] **Step 5: Correr el test (debe pasar)**

Run: `php artisan test --filter LandingSectionTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_08_150000_create_landing_sections_table.php app/Shop/Models/LandingSection.php tests/Feature/Shop/LandingSectionTest.php
git commit -m "feat(shop): tabla y modelo LandingSection"
```

---

## Task 3: Registry de tipos de sección

**Files:**
- Create: `app/Shop/Landing/SectionTypes.php`
- Test: `tests/Feature/Shop/SectionTypesTest.php`

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Shop;

use App\Shop\Landing\SectionTypes;
use Tests\TestCase;

class SectionTypesTest extends TestCase
{
    public function test_lists_the_seven_types(): void
    {
        $this->assertSame(
            ['hero', 'about', 'hours', 'categories', 'gallery', 'contact', 'cta'],
            SectionTypes::keys()
        );
    }

    public function test_exists_validates_type(): void
    {
        $this->assertTrue(SectionTypes::exists('hero'));
        $this->assertFalse(SectionTypes::exists('unknown'));
    }

    public function test_label_and_default_data_available(): void
    {
        $this->assertSame('Héroe', SectionTypes::label('hero'));
        $this->assertArrayHasKey('heading', SectionTypes::defaultData('hero'));
    }
}
```

- [ ] **Step 2: Correr el test (debe fallar)**

Run: `php artisan test --filter SectionTypesTest`
Expected: FAIL — `Class "App\Shop\Landing\SectionTypes" not found`.

- [ ] **Step 3: Implementar el registry**

`app/Shop/Landing/SectionTypes.php`:
```php
<?php

namespace App\Shop\Landing;

/**
 * Fuente única de los tipos de sección de la landing: label, partial de render
 * y data por defecto. El editor (SP2) leerá este registry para ofrecer tipos y
 * armar formularios. El render (shop.landing) lo usa para validar y ubicar partials.
 */
class SectionTypes
{
    /** type => [label, partial, default] */
    private static function map(): array
    {
        return [
            'hero' => [
                'label' => 'Héroe',
                'partial' => 'shop.landing.sections.hero',
                'default' => [
                    'heading' => 'Bienvenido a nuestra tienda',
                    'subheading' => 'Descubre nuestros productos',
                    'cta_text' => 'Entrar a la tienda',
                    'cta_target' => 'catalog',
                ],
            ],
            'about' => [
                'label' => 'Acerca / Historia',
                'partial' => 'shop.landing.sections.about',
                'default' => [
                    'heading' => 'Quiénes somos',
                    'body_html' => '<p>Cuéntale a tus clientes tu historia.</p>',
                ],
            ],
            'hours' => [
                'label' => 'Horarios',
                'partial' => 'shop.landing.sections.hours',
                'default' => [
                    'heading' => 'Horarios de atención',
                    'rows' => [
                        ['label' => 'Lunes a Viernes', 'value' => '9:00 – 18:00'],
                        ['label' => 'Sábados', 'value' => '9:00 – 13:00'],
                    ],
                ],
            ],
            'categories' => [
                'label' => 'Qué vendemos',
                'partial' => 'shop.landing.sections.categories',
                'default' => [
                    'heading' => 'Qué vendemos',
                    'source' => 'auto',
                    'items' => [],
                ],
            ],
            'gallery' => [
                'label' => 'Galería',
                'partial' => 'shop.landing.sections.gallery',
                'default' => [
                    'heading' => 'Galería',
                    'images' => [],
                ],
            ],
            'contact' => [
                'label' => 'Contacto',
                'partial' => 'shop.landing.sections.contact',
                'default' => [
                    'heading' => 'Contacto',
                    'whatsapp' => '',
                    'address' => '',
                    'email' => '',
                ],
            ],
            'cta' => [
                'label' => 'Botón a la tienda',
                'partial' => 'shop.landing.sections.cta',
                'default' => [
                    'heading' => '¿Listo para comprar?',
                    'text' => 'Explora todo nuestro catálogo.',
                    'button_text' => 'Entrar a la tienda',
                    'target' => 'catalog',
                ],
            ],
        ];
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::map());
    }

    public static function exists(string $type): bool
    {
        return array_key_exists($type, self::map());
    }

    public static function label(string $type): string
    {
        return self::map()[$type]['label'] ?? $type;
    }

    public static function partial(string $type): ?string
    {
        return self::map()[$type]['partial'] ?? null;
    }

    /** @return array<string,mixed> */
    public static function defaultData(string $type): array
    {
        return self::map()[$type]['default'] ?? [];
    }
}
```

- [ ] **Step 4: Correr el test (debe pasar)**

Run: `php artisan test --filter SectionTypesTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Shop/Landing/SectionTypes.php tests/Feature/Shop/SectionTypesTest.php
git commit -m "feat(shop): registry de tipos de seccion de landing"
```

---

## Task 4: Ruteo + split del controlador (landing vs catálogo)

**Files:**
- Modify: `routes/shop.php`
- Modify: `app/Shop/Http/Controllers/ShopController.php`
- Test: `tests/Feature/Shop/ShopLandingRoutingTest.php`

- [ ] **Step 1: Escribir el test que falla**

`tests/Feature/Shop/ShopLandingRoutingTest.php`:
```php
<?php

namespace Tests\Feature\Shop;

use App\Models\Setting;
use App\Shop\Models\LandingSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopLandingRoutingTest extends TestCase
{
    use RefreshDatabase, EnablesShop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableShop();
    }

    public function test_landing_enabled_renders_landing_sections(): void
    {
        Setting::set('shop_landing_enabled', '1');
        LandingSection::create([
            'type' => 'hero',
            'sort_order' => 0,
            'is_enabled' => true,
            'data' => ['heading' => 'MARCA_LANDING_HERO', 'subheading' => 'sub', 'cta_text' => 'Entrar', 'cta_target' => 'catalog'],
        ]);

        $this->get('/tienda')
            ->assertOk()
            ->assertSee('MARCA_LANDING_HERO');
    }

    public function test_landing_disabled_falls_back_to_catalog(): void
    {
        Setting::set('shop_landing_enabled', '0');

        // La vista de catálogo tiene el form de filtros que apunta a /tienda/catalogo.
        $this->get('/tienda')
            ->assertOk()
            ->assertSee(route('shop.catalog'), false);
    }

    public function test_catalog_route_renders_catalog(): void
    {
        $this->get('/tienda/catalogo')
            ->assertOk()
            ->assertSee(route('shop.catalog'), false);
    }
}
```

- [ ] **Step 2: Correr el test (debe fallar)**

Run: `php artisan test --filter ShopLandingRoutingTest`
Expected: FAIL — ruta `shop.catalog` no existe / método `catalog` no definido.

- [ ] **Step 3: Agregar la ruta de catálogo**

En `routes/shop.php`, dentro del grupo, tras la línea de `index`:
```php
Route::get('/', [ShopController::class, 'index'])->name('index');
Route::get('/catalogo', [ShopController::class, 'catalog'])->name('catalog');
```

- [ ] **Step 4: Reescribir el controlador (index despacha, catalog = lógica actual)**

En `app/Shop/Http/Controllers/ShopController.php`:

Agregar imports arriba:
```php
use App\Shop\Models\LandingSection;
```

Reemplazar el método `index()` completo (líneas 29-91) por estos dos métodos. `index()` decide;
`catalog()` contiene EXACTAMENTE el cuerpo que hoy tiene `index()`:
```php
    /**
     * Punto de entrada de /tienda. Muestra la landing si está activada
     * (shop_landing_enabled='1', default), o el catálogo si no.
     */
    public function index(Request $request): View
    {
        if (Setting::get('shop_landing_enabled', '1') !== '1') {
            return $this->catalog($request);
        }

        $sections = LandingSection::enabled()->ordered()->get();

        return view('shop.landing', [
            'sections' => $sections,
            'shopCategories' => $this->publicCategories(),
        ]);
    }

    /**
     * Catálogo con sidebar de filtros (categoría, precio) + ordenamiento.
     * (Antes era el cuerpo de index(); ahora vive en /tienda/catalogo.)
     */
    public function catalog(Request $request): View
    {
        $query = Product::query()
            ->public()
            ->with(['primaryImage', 'category', 'unit']);

        $categoryId = $request->integer('category');
        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $min = $request->integer('min');
        $max = $request->integer('max');
        if ($min > 0) {
            $query->where('selling_price', '>=', $min * 100);
        }
        if ($max > 0) {
            $query->where('selling_price', '<=', $max * 100);
        }

        match ($request->input('sort', 'newest')) {
            'price_asc'  => $query->orderBy('selling_price', 'asc'),
            'price_desc' => $query->orderBy('selling_price', 'desc'),
            'name'       => $query->orderBy('name', 'asc'),
            default      => $query->orderByDesc('featured')->orderByDesc('id'),
        };

        $products = $query->paginate(24)->withQueryString();

        $priceRange = Cache::remember('shop.price_range', 300, function () {
            $min = Product::query()->public()->min('selling_price') ?? 0;
            $max = Product::query()->public()->max('selling_price') ?? 0;
            return [
                'min' => (int) floor($min / 100),
                'max' => (int) ceil($max / 100),
            ];
        });

        return view('shop.index', [
            'products' => $products,
            'categories' => $this->publicCategories(),
            'priceRange' => $priceRange,
            'selectedCategory' => $categoryId,
            'selectedMin' => $min,
            'selectedMax' => $max,
            'selectedSort' => $request->input('sort', 'newest'),
            'searchQuery' => $request->input('q'),
        ]);
    }

    /**
     * Categorías con al menos 1 producto público (cacheado 5 min).
     * Compartido entre el catálogo (sidebar) y la landing (sección "qué vendemos").
     */
    private function publicCategories()
    {
        return Cache::remember('shop.categories_with_public_products', 300, function () {
            return Category::query()
                ->whereHas('products', fn ($q) => $q->public())
                ->withCount(['products as public_products_count' => fn ($q) => $q->public()])
                ->orderBy('name')
                ->get();
        });
    }
```

Nota: `catalog()` reusa `publicCategories()` en vez de la query inline previa (misma clave de cache,
mismo resultado) para no duplicar. El resto de métodos (`show`, `search`) queda igual.

- [ ] **Step 5: Crear un stub mínimo de la vista landing para que el test corra**

(La versión completa se hace en Task 5; aquí un stub para verificar el ruteo.)
`resources/views/shop/landing.blade.php`:
```blade
@extends('shop.layouts.app')
@section('title', 'Inicio')
@section('content')
    @foreach($sections as $section)
        @if(\App\Shop\Landing\SectionTypes::exists($section->type))
            @include(\App\Shop\Landing\SectionTypes::partial($section->type), ['data' => $section->data ?? []])
        @endif
    @endforeach
@endsection
```

Y un stub del partial hero para que el test de "landing renderiza" vea el heading:
`resources/views/shop/landing/sections/hero.blade.php`:
```blade
<section><h1>{{ $data['heading'] ?? '' }}</h1></section>
```

- [ ] **Step 6: Correr el test (debe pasar)**

Run: `php artisan test --filter ShopLandingRoutingTest`
Expected: PASS (3 tests). Nota: `test_landing_disabled_falls_back_to_catalog` y `test_catalog_route_renders_catalog`
dependen de que la vista de catálogo `shop.index` referencie `route('shop.catalog')` — eso se hace en Task 6.
Si corren antes de Task 6, esos 2 pueden fallar el `assertSee(route('shop.catalog'))`. **Ejecutar Task 6
inmediatamente después** y re-correr. (Para mantener verde este task de forma aislada, es aceptable dejar
esos 2 asserts y confirmarlos al cerrar Task 6.)

- [ ] **Step 7: Commit**

```bash
git add routes/shop.php app/Shop/Http/Controllers/ShopController.php resources/views/shop/landing.blade.php resources/views/shop/landing/sections/hero.blade.php tests/Feature/Shop/ShopLandingRoutingTest.php
git commit -m "feat(shop): ruteo landing en /tienda y catalogo en /tienda/catalogo"
```

---

## Task 5: Vista landing completa + 7 partials de sección

**Files:**
- Modify: `resources/views/shop/landing.blade.php` (ya creada como stub)
- Create/replace: `resources/views/shop/landing/sections/{hero,about,hours,categories,gallery,contact,cta}.blade.php`
- Test: extender `ShopLandingRoutingTest` con render por tipo + saneo.

Cada partial recibe `['data' => array]`. La vista landing pasa además `$shopCategories` a scope de la
vista (disponible en el partial `categories` vía la variable heredada del `@include` padre — se pasa
explícito). Los cuerpos ricos se sanean en el partial (defensa en profundidad) con `LandingHtmlSanitizer`.

- [ ] **Step 1: Escribir el test que falla (render por tipo + saneo)**

Agregar a `tests/Feature/Shop/ShopLandingRoutingTest.php`:
```php
    public function test_about_body_html_is_sanitized_on_render(): void
    {
        Setting::set('shop_landing_enabled', '1');
        LandingSection::create([
            'type' => 'about',
            'sort_order' => 0,
            'is_enabled' => true,
            'data' => ['heading' => 'Historia', 'body_html' => '<p>Ok</p><script>alert(1)</script>'],
        ]);

        $res = $this->get('/tienda')->assertOk();
        $res->assertSee('Historia');
        $res->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_cta_section_links_to_catalog(): void
    {
        Setting::set('shop_landing_enabled', '1');
        LandingSection::create([
            'type' => 'cta',
            'sort_order' => 0,
            'is_enabled' => true,
            'data' => ['button_text' => 'Entrar a la tienda', 'target' => 'catalog'],
        ]);

        $this->get('/tienda')
            ->assertOk()
            ->assertSee(route('shop.catalog'), false)
            ->assertSee('Entrar a la tienda');
    }

    public function test_disabled_section_is_not_rendered(): void
    {
        Setting::set('shop_landing_enabled', '1');
        LandingSection::create([
            'type' => 'hero',
            'sort_order' => 0,
            'is_enabled' => false,
            'data' => ['heading' => 'OCULTO_HERO'],
        ]);

        $this->get('/tienda')->assertOk()->assertDontSee('OCULTO_HERO');
    }
```

- [ ] **Step 2: Correr (debe fallar)**

Run: `php artisan test --filter ShopLandingRoutingTest`
Expected: FAIL — `test_about_body_html_is_sanitized_on_render` (partial about es stub/ausente) y
`test_cta_section_links_to_catalog` (partial cta ausente).

- [ ] **Step 3: Escribir el partial `hero` (reemplaza el stub)**

`resources/views/shop/landing/sections/hero.blade.php`:
```blade
@php
    $ctaUrl = match($data['cta_target'] ?? 'catalog') {
        'catalog' => route('shop.catalog'),
        'whatsapp' => 'https://wa.me/' . preg_replace('/\D/', '', (string) \App\Models\Setting::get('shop_whatsapp_number', '')),
        default => $data['cta_target'],
    };
    $bg = $data['background_image_path'] ?? null;
@endphp
<section class="relative overflow-hidden"
         style="background: {{ $bg ? 'url('.\Illuminate\Support\Facades\Storage::url($bg).') center/cover' : 'linear-gradient(135deg, var(--shop-primary), var(--shop-secondary))' }}; color: var(--shop-text-on-primary)">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-20 md:py-28 text-center">
        <h1 class="text-4xl md:text-6xl font-extrabold tracking-tight">{{ $data['heading'] ?? '' }}</h1>
        @isset($data['subheading'])
            <p class="mt-4 text-lg md:text-2xl opacity-90 max-w-2xl mx-auto">{{ $data['subheading'] }}</p>
        @endisset
        @if(!empty($data['cta_text']))
            <a href="{{ $ctaUrl }}"
               class="inline-block mt-8 px-8 py-3 rounded-full font-semibold text-base shadow-lg transition-transform hover:scale-105"
               style="background-color: var(--shop-text-on-primary); color: var(--shop-primary)">
                {{ $data['cta_text'] }}
            </a>
        @endif
    </div>
</section>
```

- [ ] **Step 4: Escribir el partial `about`**

`resources/views/shop/landing/sections/about.blade.php`:
```blade
@php
    $clean = app(\App\Shop\Services\LandingHtmlSanitizer::class)->sanitize($data['body_html'] ?? '');
    $img = $data['image_path'] ?? null;
@endphp
<section class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    <div class="grid md:grid-cols-[1fr_auto] gap-8 items-center">
        <div>
            @isset($data['heading'])
                <h2 class="text-3xl font-bold mb-4" style="color: var(--shop-primary)">{{ $data['heading'] }}</h2>
            @endisset
            <div class="prose max-w-none text-zinc-700 leading-relaxed">{!! $clean !!}</div>
        </div>
        @if($img)
            <img src="{{ \Illuminate\Support\Facades\Storage::url($img) }}" alt="{{ $data['heading'] ?? '' }}"
                 class="rounded-2xl shadow-lg w-full max-w-sm object-cover">
        @endif
    </div>
</section>
```

- [ ] **Step 5: Escribir el partial `hours`**

`resources/views/shop/landing/sections/hours.blade.php`:
```blade
<section class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    @isset($data['heading'])
        <h2 class="text-3xl font-bold mb-6 text-center" style="color: var(--shop-primary)">{{ $data['heading'] }}</h2>
    @endisset
    <div class="rounded-2xl border border-zinc-200 bg-white divide-y divide-zinc-100">
        @foreach(($data['rows'] ?? []) as $row)
            <div class="flex items-center justify-between px-6 py-4">
                <span class="font-medium text-zinc-800">{{ $row['label'] ?? '' }}</span>
                <span class="text-zinc-600">{{ $row['value'] ?? '' }}</span>
            </div>
        @endforeach
    </div>
</section>
```

- [ ] **Step 6: Escribir el partial `categories` (qué vendemos)**

`resources/views/shop/landing/sections/categories.blade.php`:
```blade
@php
    // source=auto → categorías públicas compartidas por el controlador ($shopCategories).
    // source=manual → items definidos en data.
    $auto = ($data['source'] ?? 'auto') === 'auto';
    $cats = $auto ? ($shopCategories ?? collect()) : collect($data['items'] ?? []);
@endphp
<section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    @isset($data['heading'])
        <h2 class="text-3xl font-bold mb-8 text-center" style="color: var(--shop-primary)">{{ $data['heading'] }}</h2>
    @endisset
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        @if($auto)
            @foreach($cats as $cat)
                <a href="{{ route('shop.catalog', ['category' => $cat->id]) }}"
                   class="rounded-2xl border border-zinc-200 bg-white p-6 text-center hover:shadow-md transition-shadow">
                    <p class="font-semibold text-zinc-800">{{ $cat->name }}</p>
                    <p class="text-xs text-zinc-500 mt-1">{{ $cat->public_products_count }} productos</p>
                </a>
            @endforeach
        @else
            @foreach($cats as $item)
                <a href="{{ $item['link'] ?? route('shop.catalog') }}"
                   class="rounded-2xl border border-zinc-200 bg-white p-6 text-center hover:shadow-md transition-shadow">
                    <p class="font-semibold text-zinc-800">{{ $item['label'] ?? '' }}</p>
                </a>
            @endforeach
        @endif
    </div>
</section>
```

- [ ] **Step 7: Escribir el partial `gallery`**

`resources/views/shop/landing/sections/gallery.blade.php`:
```blade
<section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    @isset($data['heading'])
        <h2 class="text-3xl font-bold mb-8 text-center" style="color: var(--shop-primary)">{{ $data['heading'] }}</h2>
    @endisset
    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
        @foreach(($data['images'] ?? []) as $img)
            <img src="{{ \Illuminate\Support\Facades\Storage::url($img) }}" alt=""
                 class="rounded-xl object-cover w-full aspect-square bg-zinc-100">
        @endforeach
    </div>
</section>
```

- [ ] **Step 8: Escribir el partial `contact`**

`resources/views/shop/landing/sections/contact.blade.php`:
```blade
@php
    $wa = preg_replace('/\D/', '', (string) ($data['whatsapp'] ?? ''));
@endphp
<section class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-16 text-center">
    @isset($data['heading'])
        <h2 class="text-3xl font-bold mb-6" style="color: var(--shop-primary)">{{ $data['heading'] }}</h2>
    @endisset
    <div class="space-y-2 text-zinc-700">
        @if($wa)
            <p><a href="https://wa.me/{{ $wa }}" class="underline" style="color: var(--shop-primary)">WhatsApp: {{ $data['whatsapp'] }}</a></p>
        @endif
        @if(!empty($data['address']))<p>{{ $data['address'] }}</p>@endif
        @if(!empty($data['email']))<p><a href="mailto:{{ $data['email'] }}" class="underline">{{ $data['email'] }}</a></p>@endif
    </div>
</section>
```

- [ ] **Step 9: Escribir el partial `cta`**

`resources/views/shop/landing/sections/cta.blade.php`:
```blade
@php
    $ctaUrl = match($data['target'] ?? 'catalog') {
        'catalog' => route('shop.catalog'),
        'whatsapp' => 'https://wa.me/' . preg_replace('/\D/', '', (string) \App\Models\Setting::get('shop_whatsapp_number', '')),
        default => $data['target'],
    };
@endphp
<section class="py-16" style="background: linear-gradient(135deg, var(--shop-primary), var(--shop-secondary)); color: var(--shop-text-on-primary)">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        @isset($data['heading'])
            <h2 class="text-3xl md:text-4xl font-bold">{{ $data['heading'] }}</h2>
        @endisset
        @if(!empty($data['text']))
            <p class="mt-3 text-lg opacity-90">{{ $data['text'] }}</p>
        @endif
        <a href="{{ $ctaUrl }}"
           class="inline-block mt-8 px-8 py-3 rounded-full font-semibold text-base shadow-lg transition-transform hover:scale-105"
           style="background-color: var(--shop-text-on-primary); color: var(--shop-primary)">
            {{ $data['button_text'] ?? 'Entrar a la tienda' }}
        </a>
    </div>
</section>
```

- [ ] **Step 10: Pasar `$shopCategories` al partial `categories` desde la vista landing**

Reemplazar el `@include` de `resources/views/shop/landing.blade.php` para propagar `$shopCategories`:
```blade
@extends('shop.layouts.app')
@section('title', 'Inicio')
@section('content')
    @foreach($sections as $section)
        @if(\App\Shop\Landing\SectionTypes::exists($section->type))
            @include(\App\Shop\Landing\SectionTypes::partial($section->type), [
                'data' => $section->data ?? [],
                'shopCategories' => $shopCategories ?? collect(),
            ])
        @endif
    @endforeach
@endsection
```

- [ ] **Step 11: Correr el test (debe pasar)**

Run: `php artisan test --filter ShopLandingRoutingTest`
Expected: PASS (los tests de render/saneo/cta/disabled verdes; los 2 asserts de `route('shop.catalog')`
en catálogo se confirman al terminar Task 6).

- [ ] **Step 12: Commit**

```bash
git add resources/views/shop/landing.blade.php resources/views/shop/landing/sections/ tests/Feature/Shop/ShopLandingRoutingTest.php
git commit -m "feat(shop): vista landing con 7 partials de seccion + saneo en render"
```

---

## Task 6: Actualizar links internos hacia el catálogo

Los enlaces que llevan al **catálogo** deben apuntar a `shop.catalog`. Los que son **home** (logo,
breadcrumb "Inicio") quedan en `shop.index` (landing).

**Files:**
- Modify: `resources/views/shop/index.blade.php` (líneas 25, 90, 176, 206)
- Modify: `resources/views/shop/product.blade.php` (línea 38)
- Modify: `resources/views/shop/checkout.blade.php` (líneas 26, 141)

Dejar SIN cambio (siguen apuntando a `shop.index` = landing/home):
- `resources/views/shop/layouts/app.blade.php:48` (logo → home)
- `resources/views/shop/product.blade.php:35` (breadcrumb "Inicio")
- `resources/views/shop/checkout.blade.php:16` (breadcrumb "Inicio")

- [ ] **Step 1: `index.blade.php` — forms de filtro y "limpiar filtros" → `shop.catalog`**

Reemplazar en las 4 ubicaciones `route('shop.index')` por `route('shop.catalog')`:
- Línea 25: `<form method="GET" action="{{ route('shop.catalog') }}" id="filter-form" ...>`
- Línea 90: `<a href="{{ route('shop.catalog') }}" ...>` (limpiar/ver todo)
- Línea 176: `<form method="GET" action="{{ route('shop.catalog') }}" ...>` (filtros móvil)
- Línea 206: `<a href="{{ route('shop.catalog') }}" ...>Limpiar filtros</a>`

- [ ] **Step 2: `product.blade.php:38` — link de categoría → catálogo filtrado**

```blade
<a href="{{ route('shop.catalog', ['category' => $product->category->id]) }}" class="hover:text-zinc-900">{{ $product->category->name }}</a>
```

- [ ] **Step 3: `checkout.blade.php` — "Ver catálogo" (26) y "Seguir comprando" (141) → `shop.catalog`**

- Línea 26: `<a href="{{ route('shop.catalog') }}" class="shop-btn-primary">Ver catálogo</a>`
- Línea 141: `<a href="{{ route('shop.catalog') }}" class="block text-center mt-4 text-sm text-zinc-500 hover:text-zinc-900">`

- [ ] **Step 4: Correr los tests de ruteo (ahora todos verdes)**

Run: `php artisan test --filter ShopLandingRoutingTest`
Expected: PASS (todos, incluyendo los asserts de `route('shop.catalog')` en la vista de catálogo).

- [ ] **Step 5: Commit**

```bash
git add resources/views/shop/index.blade.php resources/views/shop/product.blade.php resources/views/shop/checkout.blade.php
git commit -m "refactor(shop): links de catalogo apuntan a shop.catalog"
```

---

## Task 7: Migración sembradora de la plantilla por defecto + flag

**Files:**
- Create: `database/migrations/2026_07_08_150100_seed_default_landing_template.php`
- Test: `tests/Feature/Shop/ShopLandingRoutingTest.php` (agregar test de idempotencia)

- [ ] **Step 1: Escribir el test que falla (idempotencia + default ON)**

Agregar a `ShopLandingRoutingTest`:
```php
    public function test_default_template_seeded_and_idempotent(): void
    {
        // La migración ya corrió (RefreshDatabase). Debe haber secciones por defecto.
        $count = \App\Shop\Models\LandingSection::count();
        $this->assertGreaterThan(0, $count);

        // Re-ejecutar la lógica de siembra no debe duplicar.
        (new \Database\Seeders\DefaultLandingTemplateSeeder())->run();
        $this->assertSame($count, \App\Shop\Models\LandingSection::count());

        // Flag activado por defecto.
        $this->assertSame('1', \App\Models\Setting::get('shop_landing_enabled'));
    }
```

Nota: la siembra vive en un seeder reusable `DefaultLandingTemplateSeeder` (idempotente) y la migración
solo lo invoca — así el test puede re-ejecutar la siembra sin re-correr la migración.

- [ ] **Step 2: Crear el seeder idempotente**

`database/seeders/DefaultLandingTemplateSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Shop\Models\LandingSection;
use Illuminate\Database\Seeder;

class DefaultLandingTemplateSeeder extends Seeder
{
    public function run(): void
    {
        // Activa la landing por defecto (solo si no está seteada).
        if (Setting::get('shop_landing_enabled') === null) {
            Setting::set('shop_landing_enabled', '1');
        }

        // Idempotente: si ya hay secciones, no siembra.
        if (LandingSection::count() > 0) {
            return;
        }

        $sections = [
            ['type' => 'hero', 'data' => [
                'heading' => 'Bienvenido a nuestra tienda',
                'subheading' => 'Descubre nuestros productos y compra fácil.',
                'cta_text' => 'Entrar a la tienda',
                'cta_target' => 'catalog',
            ]],
            ['type' => 'about', 'data' => [
                'heading' => 'Quiénes somos',
                'body_html' => '<p>Somos un negocio comprometido con ofrecerte los mejores productos y atención. Edita este texto desde Ajustes.</p>',
            ]],
            ['type' => 'hours', 'data' => [
                'heading' => 'Horarios de atención',
                'rows' => [
                    ['label' => 'Lunes a Viernes', 'value' => '9:00 – 18:00'],
                    ['label' => 'Sábados', 'value' => '9:00 – 13:00'],
                ],
            ]],
            ['type' => 'categories', 'data' => [
                'heading' => 'Qué vendemos',
                'source' => 'auto',
                'items' => [],
            ]],
            ['type' => 'cta', 'data' => [
                'heading' => '¿Listo para comprar?',
                'text' => 'Explora todo nuestro catálogo.',
                'button_text' => 'Entrar a la tienda',
                'target' => 'catalog',
            ]],
        ];

        foreach ($sections as $i => $s) {
            LandingSection::create([
                'type' => $s['type'],
                'sort_order' => $i,
                'is_enabled' => true,
                'data' => $s['data'],
            ]);
        }
    }
}
```

- [ ] **Step 3: Crear la migración que invoca el seeder**

`database/migrations/2026_07_08_150100_seed_default_landing_template.php`:
```php
<?php

use Database\Seeders\DefaultLandingTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new DefaultLandingTemplateSeeder())->run();
    }

    public function down(): void
    {
        // No-op: no borramos contenido del usuario en rollback.
    }
};
```

- [ ] **Step 4: Correr el test (debe pasar)**

Run: `php artisan test --filter ShopLandingRoutingTest`
Expected: PASS (incluye idempotencia + flag por defecto). Con `RefreshDatabase`, la migración
sembradora corre y crea la plantilla; el resto de tests que crean sus propias secciones siguen
verdes porque cuentan/asertan sobre lo que insertan (revisar: los tests que asertan `assertDontSee`
sobre marcas propias no chocan con la plantilla por defecto; el de "landing_enabled renders" asegura
ver su propia marca `MARCA_LANDING_HERO`, presente junto a la plantilla).

Nota para el implementador: si algún test previo asume tabla vacía, ajustarlo para contar deltas en
vez de totales absolutos. `test_disabled_section_is_not_rendered` usa `assertDontSee('OCULTO_HERO')`
(seguro). `test_default_template_seeded_and_idempotent` cuenta el total sembrado (correcto en
RefreshDatabase, que corre solo las migraciones).

- [ ] **Step 5: Commit**

```bash
git add database/seeders/DefaultLandingTemplateSeeder.php database/migrations/2026_07_08_150100_seed_default_landing_template.php tests/Feature/Shop/ShopLandingRoutingTest.php
git commit -m "feat(shop): plantilla de landing por defecto (seeder idempotente + migracion)"
```

---

## Cierre

- [ ] **Correr la suite completa de shop + tocada**

Run: `php artisan test --filter Shop`
Expected: PASS (todos los tests de `tests/Feature/Shop/`).

- [ ] **Correr la suite completa**

Run: `php artisan test`
Expected: PASS (sin regresiones). Si hay tests previos que asumían que `/tienda` = catálogo, actualizarlos
para el nuevo contrato (landing por defecto; catálogo en `/tienda/catalogo`). Revisar especialmente
`ReservationControllerTest` si navega a `/tienda` esperando productos.

- [ ] **Deploy (nota para producción)**

`php artisan migrate` (aditiva, **NUNCA** `:fresh`) crea la tabla + siembra la plantilla + activa
`shop_landing_enabled`. Luego `php artisan cache:clear` (o `optimize:clear`) para refrescar settings/vistas.

Al terminar todas las tasks → **superpowers:finishing-a-development-branch**.

---

## Self-review (checklist del autor)

- **Cobertura de spec:** R1 (Task 2), R2 (Task 3+5), R3 (Task 1), R4 (Task 4), R5 (Task 4 + Task 7 flag),
  R6 (Task 5), R7 (Task 6), R8 (Task 7), R9 (Task 5 partial categories auto/manual). ✔
- **Sin placeholders:** todo el código está completo e inline. ✔
- **Consistencia de tipos:** `LandingSection` (fillable/casts/scopes `enabled`/`ordered`) usados igual en
  controller, seeder y tests. `SectionTypes::{keys,exists,label,partial,defaultData}` consistentes.
  `LandingHtmlSanitizer::sanitize` firma única. Partials reciben siempre `['data' => array]` (+`shopCategories`
  en landing). ✔
- **Riesgo conocido:** el orden Task 4→6 deja 2 asserts de `route('shop.catalog')` dependientes de Task 6;
  documentado en Task 4 Step 6. Ejecutar 4,5,6 en secuencia y confirmar verde al cerrar 6. ✔
