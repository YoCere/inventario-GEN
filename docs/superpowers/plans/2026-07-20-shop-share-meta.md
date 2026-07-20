# Compartir el enlace de la tienda (SEO / Open Graph) — Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que la landing, el catálogo y la ficha de producto se vean bien al compartirlas por WhatsApp o redes: título, descripción e imagen, armados solos y editables.

**Architecture:** Un objeto de valor `ShareMeta` y un `ShareMetaBuilder` con una fábrica por página concentran TODA la lógica de respaldo. Un partial único incluido desde el layout de la tienda emite las etiquetas. Los controladores solo pasan `$shareMeta`. Los overrides viven en tres ajustes editables desde un componente Livewire propio en la página del editor de landing, con vista previa de la tarjeta.

**Tech Stack:** Laravel 11, Livewire 3, Blade, Tailwind, PHPUnit (class-style), MySQL. Sin dependencias nuevas.

**Spec:** `docs/superpowers/specs/2026-07-20-shop-share-meta-design.md`

---

## Convenciones del repo (leer antes de empezar)

- **NUNCA `migrate:fresh`/`migrate:refresh`** (MySQL dev compartido). Este plan no agrega migraciones:
  los ajustes nuevos son filas de `settings` creadas al guardar.
- Tests: clase PHPUnit, `extends Tests\TestCase`, `use RefreshDatabase`. Para tocar `/tienda/*` hace falta
  el trait `tests/Feature/Shop/EnablesShop.php` (`enableShop()` en `setUp()`), porque las rutas de la
  tienda solo se registran con `shop_enabled='1'`.
- Correr: `php artisan test --filter <Clase>`. Suite completa: `php artisan test` (~16 min).
- Livewire: componentes auto-descubiertos en `App\Livewire`. Tests con `Livewire::test(...)`.
- Blade admin usa tokens: `text-foreground`, `text-muted-foreground`, `border-border`, `bg-background`,
  `bg-accent`, `border-input`; botones `<x-primary-button>` / `<x-secondary-button>`.
- Una migración de SP1 siembra secciones de landing en cada build de `RefreshDatabase`; los tests que
  necesiten controlar el contenido exacto hacen `LandingSection::query()->delete()` en `setUp()`.

## Estado heredado (ya en main, no reimplementar)

- `App\Shop\Models\LandingSection` — scopes `enabled()`, `ordered()`; `data` casteado a array.
- `App\Shop\Landing\LandingUrl::safeStoragePath(?string): ?string` — rechaza rutas peligrosas.
- `App\Shop\Landing\LandingImages` — `store(UploadedFile): string`, `delete(?string): void`.
- `App\Models\Setting::get($key, $default)` / `set($key, $value)`.
- Permiso `shop.landing.manage` (SP2). **Invariante SP2: cada método público de un componente Livewire
  re-chequea el permiso** — el middleware `can:` de la ruta solo corre en el GET inicial.
- `resources/views/shop/layouts/app.blade.php` — ya calcula `$businessName` y `$logoUrl` (líneas 5-7) y
  tiene `@stack('head')` (línea 37).
- `resources/views/shop/product.blade.php:24-28` — OG escrito a mano, **se elimina en Task 2**.
- `App\Models\Product::getCardImageUrlAttribute()` — devuelve `Storage::url(...)`, es decir **ruta
  relativa**; por eso todo pasa por `url()`.

---

## File Structure

- Create `app/Shop/Seo/ShareMeta.php` — objeto de valor inmutable.
- Create `app/Shop/Seo/ShareMetaBuilder.php` — fábricas + cadenas de respaldo.
- Create `resources/views/shop/partials/share-meta.blade.php` — emisión de etiquetas.
- Modify `resources/views/shop/layouts/app.blade.php` — incluye el partial + favicon.
- Modify `app/Shop/Http/Controllers/ShopController.php` — pasa `$shareMeta` en `index`/`catalog`/`show`.
- Modify `app/Shop/Http/Controllers/ReservationController.php` — pasa `$shareMeta` en `checkout`.
- Modify `resources/views/shop/product.blade.php` — quita el `@push('head')`.
- Create `app/Livewire/Settings/LandingShareSettings.php` + `resources/views/livewire/settings/landing-share-settings.blade.php`.
- Modify `resources/views/settings/landing.blade.php` — monta el componente.
- Tests: `tests/Feature/Shop/ShareMetaBuilderTest.php`, `tests/Feature/Shop/ShareMetaRenderTest.php`,
  `tests/Feature/Settings/LandingShareSettingsTest.php`.

---

## Task 1: `ShareMeta` + `ShareMetaBuilder`

**Files:**
- Create: `app/Shop/Seo/ShareMeta.php`, `app/Shop/Seo/ShareMetaBuilder.php`
- Test: `tests/Feature/Shop/ShareMetaBuilderTest.php`

- [ ] **Step 1: Escribir el test que falla**

`tests/Feature/Shop/ShareMetaBuilderTest.php`:
```php
<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Unit;
use App\Shop\Models\LandingSection;
use App\Shop\Seo\ShareMetaBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShareMetaBuilderTest extends TestCase
{
    use RefreshDatabase, EnablesShop;

    private ShareMetaBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableShop(); // registra route('shop.index') etc.
        LandingSection::query()->delete();

        $this->builder = new ShareMetaBuilder();
    }

    private function hero(array $data): void
    {
        LandingSection::create([
            'type' => 'hero', 'sort_order' => 0, 'is_enabled' => true, 'data' => $data,
        ]);
    }

    public function test_landing_title_prefers_override_then_business_name(): void
    {
        Setting::set('shop_business_name', 'Mi Negocio');

        $this->assertSame('Mi Negocio', $this->builder->forLanding()->title);

        Setting::set('shop_share_title', 'Título propio');
        $this->assertSame('Título propio', $this->builder->forLanding()->title);
    }

    public function test_landing_description_falls_back_from_override_to_hero_to_welcome(): void
    {
        Setting::set('shop_welcome_message', 'Bienvenida');
        $this->assertSame('Bienvenida', $this->builder->forLanding()->description);

        $this->hero(['heading' => 'H', 'subheading' => 'Subtítulo del héroe']);
        $this->assertSame('Subtítulo del héroe', $this->builder->forLanding()->description);

        Setting::set('shop_share_description', 'Descripción propia');
        $this->assertSame('Descripción propia', $this->builder->forLanding()->description);
    }

    public function test_landing_image_falls_back_from_override_to_hero_to_logo(): void
    {
        $this->assertNull($this->builder->forLanding()->imageUrl);

        Setting::set('shop_logo_path', 'shop/logo.png');
        $this->assertStringContainsString('shop/logo.png', $this->builder->forLanding()->imageUrl);

        $this->hero(['heading' => 'H', 'background_image_path' => 'shop/landing/hero.jpg']);
        $this->assertStringContainsString('shop/landing/hero.jpg', $this->builder->forLanding()->imageUrl);

        Setting::set('shop_share_image_path', 'shop/landing/share.jpg');
        $this->assertStringContainsString('shop/landing/share.jpg', $this->builder->forLanding()->imageUrl);
    }

    public function test_image_url_is_always_absolute(): void
    {
        Setting::set('shop_share_image_path', 'shop/landing/share.jpg');

        $this->assertStringStartsWith('http', $this->builder->forLanding()->imageUrl);
    }

    public function test_disabled_hero_is_ignored(): void
    {
        LandingSection::create([
            'type' => 'hero', 'sort_order' => 0, 'is_enabled' => false,
            'data' => ['heading' => 'H', 'subheading' => 'Oculto'],
        ]);
        Setting::set('shop_welcome_message', 'Bienvenida');

        $this->assertSame('Bienvenida', $this->builder->forLanding()->description);
    }

    public function test_catalog_title_includes_business_name(): void
    {
        Setting::set('shop_business_name', 'Mi Negocio');

        $this->assertStringContainsString('Mi Negocio', $this->builder->forCatalog()->title);
        $this->assertSame(route('shop.catalog'), $this->builder->forCatalog()->url);
    }

    public function test_product_uses_its_own_name_and_description(): void
    {
        $product = $this->makeProduct('Zapato Rojo', '<p>Muy <b>cómodo</b></p>');

        $meta = $this->builder->forProduct($product);

        $this->assertSame('Zapato Rojo', $meta->title);
        $this->assertSame('Muy cómodo', $meta->description);
        $this->assertSame('product', $meta->type);
        $this->assertSame(route('shop.product', $product->slug), $meta->url);
    }

    public function test_product_without_description_falls_back_to_landing_description(): void
    {
        Setting::set('shop_share_description', 'Descripción de la tienda');
        $product = $this->makeProduct('Sin Desc', null);

        $this->assertSame('Descripción de la tienda', $this->builder->forProduct($product)->description);
    }

    public function test_checkout_is_noindex(): void
    {
        $this->assertTrue($this->builder->forCheckout()->noindex);
        $this->assertFalse($this->builder->forLanding()->noindex);
    }

    private function makeProduct(string $name, ?string $description): Product
    {
        $this->seed(\Database\Seeders\CategorySeeder::class);
        $this->seed(\Database\Seeders\UnitSeeder::class);

        return Product::create([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'sku' => strtoupper(\Illuminate\Support\Str::random(8)),
            'description' => $description,
            'category_id' => Category::first()->id,
            'unit_id' => Unit::first()->id,
            'selling_price' => 10000,
            'quantity' => 5,
        ]);
    }
}
```

NOTA para el implementador: `makeProduct()` es un esbozo — **leer `database/factories/ProductFactory.php`
y `tests/Feature/Shop/ReservationControllerTest.php` primero** y usar la factory/convención real del repo
para crear productos (campos obligatorios, `public()` scope, etc.). La aserción es lo que importa, no
cómo se crea el producto.

- [ ] **Step 2: Correr — debe fallar**

Run: `php artisan test --filter ShareMetaBuilderTest`
Expected: FAIL — `Class "App\Shop\Seo\ShareMetaBuilder" not found`.

- [ ] **Step 3: Implementar el objeto de valor**

`app/Shop/Seo/ShareMeta.php`:
```php
<?php

namespace App\Shop\Seo;

/**
 * Metadatos de una página pública de la tienda para vistas previas al compartir
 * (Open Graph / Twitter Card) y para buscadores. Contenedor inmutable: toda la
 * lógica de respaldo vive en ShareMetaBuilder.
 */
class ShareMeta
{
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        /** URL ABSOLUTA de la imagen, o null si no hay ninguna. */
        public readonly ?string $imageUrl,
        /** URL ABSOLUTA canónica de la página. */
        public readonly string $url,
        public readonly string $type = 'website',
        public readonly bool $noindex = false,
    ) {}
}
```

- [ ] **Step 4: Implementar el constructor**

`app/Shop/Seo/ShareMetaBuilder.php`:
```php
<?php

namespace App\Shop\Seo;

use App\Models\Product;
use App\Models\Setting;
use App\Shop\Models\LandingSection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Arma los metadatos de cada página pública aplicando cadenas de respaldo, de modo
 * que la tienda siempre se comparta con algo razonable sin obligar a configurar nada.
 *
 * Las URLs de imagen SIEMPRE salen absolutas: Storage::url() devuelve una ruta
 * relativa y WhatsApp/Facebook descartan las imágenes relativas sin avisar.
 */
class ShareMetaBuilder
{
    private const DESCRIPTION_LIMIT = 200;

    public function forLanding(): ShareMeta
    {
        return new ShareMeta(
            title: $this->landingTitle(),
            description: $this->landingDescription(),
            imageUrl: $this->landingImageUrl(),
            url: route('shop.index'),
        );
    }

    public function forCatalog(): ShareMeta
    {
        return new ShareMeta(
            title: 'Catálogo · ' . $this->businessName(),
            description: $this->landingDescription(),
            imageUrl: $this->landingImageUrl(),
            url: route('shop.catalog'),
        );
    }

    public function forProduct(Product $product): ShareMeta
    {
        $description = $this->clean($product->description ?? '');

        return new ShareMeta(
            title: $product->name,
            description: $description !== '' ? $description : $this->landingDescription(),
            imageUrl: $this->productImageUrl($product) ?? $this->landingImageUrl(),
            url: route('shop.product', $product->slug),
            type: 'product',
        );
    }

    public function forCheckout(): ShareMeta
    {
        return new ShareMeta(
            title: 'Reservar · ' . $this->businessName(),
            description: $this->landingDescription(),
            imageUrl: $this->landingImageUrl(),
            url: route('shop.checkout'),
            noindex: true, // un carrito no tiene por qué estar en Google
        );
    }

    public function businessName(): string
    {
        return Setting::get('shop_business_name') ?: (string) config('app.name');
    }

    private function landingTitle(): string
    {
        return $this->nonEmpty(Setting::get('shop_share_title')) ?? $this->businessName();
    }

    private function landingDescription(): string
    {
        return $this->clean(
            $this->nonEmpty(Setting::get('shop_share_description'))
            ?? $this->nonEmpty($this->heroData()['subheading'] ?? null)
            ?? $this->nonEmpty(Setting::get('shop_welcome_message'))
            ?? ''
        );
    }

    private function landingImageUrl(): ?string
    {
        $path = $this->nonEmpty(Setting::get('shop_share_image_path'))
            ?? $this->nonEmpty($this->heroData()['background_image_path'] ?? null)
            ?? $this->nonEmpty(Setting::get('shop_logo_path'));

        return $this->absoluteFromPath($path);
    }

    private function productImageUrl(Product $product): ?string
    {
        $image = $product->primaryImage;

        $path = $image?->path_full
            ?: $image?->path_card
            ?: $image?->path
            ?: $product->image_path;

        return $this->absoluteFromPath($this->nonEmpty($path));
    }

    /** `data` de la primera sección hero habilitada, o [] si no hay. */
    private function heroData(): array
    {
        $hero = LandingSection::query()
            ->enabled()
            ->ordered()
            ->where('type', 'hero')
            ->first();

        return $hero?->data ?? [];
    }

    /** Ruta de disco → URL absoluta. Storage::url() sola devuelve una ruta relativa. */
    private function absoluteFromPath(?string $path): ?string
    {
        return $path ? url(Storage::url($path)) : null;
    }

    private function clean(?string $text): string
    {
        return Str::limit(trim(strip_tags((string) $text)), self::DESCRIPTION_LIMIT, '');
    }

    private function nonEmpty(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
```

- [ ] **Step 5: Correr — debe pasar**

Run: `php artisan test --filter ShareMetaBuilderTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Shop/Seo tests/Feature/Shop/ShareMetaBuilderTest.php
git commit -m "feat(shop): metadatos para compartir con cadenas de respaldo"
```

---

## Task 2: Emisión de etiquetas en las 4 páginas + favicon

**Files:**
- Create: `resources/views/shop/partials/share-meta.blade.php`
- Modify: `resources/views/shop/layouts/app.blade.php`
- Modify: `app/Shop/Http/Controllers/ShopController.php`, `app/Shop/Http/Controllers/ReservationController.php`
- Modify: `resources/views/shop/product.blade.php`
- Test: `tests/Feature/Shop/ShareMetaRenderTest.php`

- [ ] **Step 1: Escribir el test que falla**

`tests/Feature/Shop/ShareMetaRenderTest.php`:
```php
<?php

namespace Tests\Feature\Shop;

use App\Models\Setting;
use App\Shop\Models\LandingSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShareMetaRenderTest extends TestCase
{
    use RefreshDatabase, EnablesShop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableShop();
    }

    public function test_landing_emits_the_open_graph_tags(): void
    {
        Setting::set('shop_landing_enabled', '1');
        Setting::set('shop_business_name', 'Mi Negocio');

        $res = $this->get('/tienda')->assertOk();

        $res->assertSee('property="og:title"', false);
        $res->assertSee('property="og:description"', false);
        $res->assertSee('property="og:url"', false);
        $res->assertSee('name="twitter:card"', false);
        $res->assertSee('rel="canonical"', false);
    }

    public function test_og_image_is_an_absolute_url(): void
    {
        Setting::set('shop_share_image_path', 'shop/landing/share.jpg');

        $html = $this->get('/tienda')->assertOk()->getContent();

        preg_match('/property="og:image" content="([^"]+)"/', $html, $m);

        $this->assertNotEmpty($m, 'No se emitió og:image');
        $this->assertStringStartsWith('http', $m[1], 'og:image debe ser absoluta o WhatsApp la ignora');
    }

    public function test_og_image_is_omitted_when_there_is_no_image(): void
    {
        Setting::set('shop_share_image_path', '');
        Setting::set('shop_logo_path', '');
        LandingSection::query()->delete();

        $this->get('/tienda')->assertOk()->assertDontSee('property="og:image"', false);
    }

    public function test_catalog_emits_tags(): void
    {
        $this->get('/tienda/catalogo')->assertOk()->assertSee('property="og:title"', false);
    }

    public function test_checkout_is_noindex(): void
    {
        $this->get('/tienda/checkout')->assertOk()->assertSee('name="robots"', false);
    }

    public function test_override_title_wins_on_the_landing(): void
    {
        Setting::set('shop_landing_enabled', '1');
        Setting::set('shop_share_title', 'MARCA_OVERRIDE');

        $this->get('/tienda')->assertOk()->assertSee('MARCA_OVERRIDE');
    }

    public function test_title_with_quotes_is_escaped(): void
    {
        Setting::set('shop_share_title', 'Rojo "fuerte" & <b>negrita</b>');

        $html = $this->get('/tienda')->assertOk()->getContent();

        $this->assertStringNotContainsString('content="Rojo "fuerte"', $html);
        $this->assertStringNotContainsString('<b>negrita</b>"', $html);
    }

    public function test_favicon_is_emitted_when_there_is_a_logo(): void
    {
        Setting::set('shop_logo_path', 'shop/logo.png');

        $this->get('/tienda')->assertOk()->assertSee('rel="icon"', false);
    }
}
```

Agregar además un test de no-duplicación en la ficha de producto. Crear el producto siguiendo la
convención real del repo (ver la nota de Task 1):
```php
    public function test_product_page_emits_tags_once(): void
    {
        // … crear un producto público con la factory del repo …
        $html = $this->get(route('shop.product', $product->slug))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'property="og:title"'), 'og:title duplicado');
    }
```

- [ ] **Step 2: Correr — debe fallar**

Run: `php artisan test --filter ShareMetaRenderTest`

- [ ] **Step 3: Crear el partial**

`resources/views/shop/partials/share-meta.blade.php`:
```blade
{{-- Etiquetas para vistas previas al compartir (WhatsApp, Facebook, X) y buscadores.
     Recibe $meta (App\Shop\Seo\ShareMeta) y $siteName. Único emisor de OG en la tienda:
     no agregar etiquetas sueltas en las vistas, se duplican. --}}
@php($meta = $meta ?? app(\App\Shop\Seo\ShareMetaBuilder::class)->forLanding())

<meta name="description" content="{{ $meta->description }}">
<link rel="canonical" href="{{ $meta->url }}">
@if($meta->noindex)
    <meta name="robots" content="noindex">
@endif

<meta property="og:type" content="{{ $meta->type }}">
<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:title" content="{{ $meta->title }}">
<meta property="og:description" content="{{ $meta->description }}">
<meta property="og:url" content="{{ $meta->url }}">
@if($meta->imageUrl)
    <meta property="og:image" content="{{ $meta->imageUrl }}">
@endif

<meta name="twitter:card" content="{{ $meta->imageUrl ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $meta->title }}">
<meta name="twitter:description" content="{{ $meta->description }}">
@if($meta->imageUrl)
    <meta name="twitter:image" content="{{ $meta->imageUrl }}">
@endif
```

- [ ] **Step 4: Incluirlo en el layout + favicon**

En `resources/views/shop/layouts/app.blade.php`, JUSTO DEBAJO de la línea del `<title>` (línea 20):
```blade
    <title>@yield('title', $businessName)</title>

    @include('shop.partials.share-meta', ['meta' => $shareMeta ?? null, 'siteName' => $businessName])

    @if($logoUrl)
        <link rel="icon" href="{{ $logoUrl }}">
    @endif
```
(`$businessName` y `$logoUrl` ya están calculados arriba en el mismo archivo.)

- [ ] **Step 5: Pasar `$shareMeta` desde `ShopController`**

Agregar el import y la inyección:
```php
use App\Shop\Seo\ShareMetaBuilder;
```
```php
    public function __construct(
        private ProductSearchService $searchService,
        private ShareMetaBuilder $shareMeta,
    ) {}
```
Y agregar la clave a los tres `view(...)`:
- en `index()` (rama landing): `'shareMeta' => $this->shareMeta->forLanding(),`
- en `catalog()`: `'shareMeta' => $this->shareMeta->forCatalog(),`
- en `show()`: `'shareMeta' => $this->shareMeta->forProduct($product),`

OJO: cuando `index()` delega en `catalog()` (landing apagada), el meta que corresponde es el del
catálogo — sale solo, porque lo arma `catalog()`.

- [ ] **Step 6: Pasar `$shareMeta` desde `ReservationController@checkout`**

```php
use App\Shop\Seo\ShareMetaBuilder;
```
```php
        return view('shop.checkout', [
            'shareMeta' => app(ShareMetaBuilder::class)->forCheckout(),
        ]);
```

- [ ] **Step 7: Quitar el OG escrito a mano del producto**

En `resources/views/shop/product.blade.php`, ELIMINAR el bloque completo de las líneas 24-28:
```blade
@push('head')
    <meta property="og:title" content="{{ $product->name }}">
    <meta property="og:image" content="{{ $primaryFull }}">
    <meta property="og:description" content="{{ Str::limit(strip_tags($product->description ?? ''), 160) }}">
@endpush
```
`$primaryFull` se sigue usando más abajo en la vista (la galería) — **no borrar el `@php` de arriba**,
solo el `@push('head')`.

- [ ] **Step 8: Correr — debe pasar**

Run: `php artisan test --filter ShareMetaRenderTest`

- [ ] **Step 9: Regresión de la tienda**

Run: `php artisan test --filter "ShopLandingRoutingTest|ShopFeatureFlagTest|ReservationControllerTest"`
Expected: PASS. (Los controladores cambiaron de firma; si algún test instancia `ShopController` a mano,
actualizarlo.)

- [ ] **Step 10: Commit**

```bash
git add resources/views/shop/partials/share-meta.blade.php resources/views/shop/layouts/app.blade.php resources/views/shop/product.blade.php app/Shop/Http/Controllers tests/Feature/Shop/ShareMetaRenderTest.php
git commit -m "feat(shop): etiquetas Open Graph en landing, catalogo, producto y checkout"
```

---

## Task 3: Panel "Cómo se ve al compartir" con vista previa

**Files:**
- Create: `app/Livewire/Settings/LandingShareSettings.php`
- Create: `resources/views/livewire/settings/landing-share-settings.blade.php`
- Modify: `resources/views/settings/landing.blade.php`
- Test: `tests/Feature/Settings/LandingShareSettingsTest.php`

- [ ] **Step 1: Escribir el test que falla**

`tests/Feature/Settings/LandingShareSettingsTest.php`:
```php
<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\LandingShareSettings;
use App\Models\Setting;
use App\Models\User;
use App\Shop\Models\LandingSection;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class LandingShareSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actingAs(User::factory()->admin()->create());
        LandingSection::query()->delete();
    }

    public function test_save_persists_the_three_settings(): void
    {
        Livewire::test(LandingShareSettings::class)
            ->set('title', 'Mi título')
            ->set('description', 'Mi descripción')
            ->call('save');

        $this->assertSame('Mi título', Setting::get('shop_share_title'));
        $this->assertSame('Mi descripción', Setting::get('shop_share_description'));
    }

    public function test_uploading_an_image_stores_it_and_replaces_the_previous(): void
    {
        $component = Livewire::test(LandingShareSettings::class)
            ->set('imageUpload', UploadedFile::fake()->image('uno.jpg'));

        $first = Setting::get('shop_share_image_path');
        Storage::disk('public')->assertExists($first);

        $component->set('imageUpload', UploadedFile::fake()->image('dos.jpg'));

        $second = Setting::get('shop_share_image_path');
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_remove_image_clears_setting_and_file(): void
    {
        $component = Livewire::test(LandingShareSettings::class)
            ->set('imageUpload', UploadedFile::fake()->image('uno.jpg'));

        $path = Setting::get('shop_share_image_path');

        $component->call('removeImage');

        Storage::disk('public')->assertMissing($path);
        $this->assertEmpty(Setting::get('shop_share_image_path'));
    }

    public function test_title_is_length_limited(): void
    {
        Livewire::test(LandingShareSettings::class)
            ->set('title', str_repeat('a', 200))
            ->call('save')
            ->assertHasErrors('title');
    }

    public function test_user_without_permission_cannot_save(): void
    {
        $component = Livewire::test(LandingShareSettings::class);

        $this->actingAs(User::factory()->staff()->create());

        $component->set('title', 'X')->call('save')->assertForbidden();
    }
}
```

- [ ] **Step 2: Correr — debe fallar**

Run: `php artisan test --filter LandingShareSettingsTest`

- [ ] **Step 3: Implementar el componente**

`app/Livewire/Settings/LandingShareSettings.php`:
```php
<?php

namespace App\Livewire\Settings;

use App\Models\Setting;
use App\Shop\Landing\LandingImages;
use App\Shop\Landing\LandingUrl;
use App\Shop\Seo\ShareMeta;
use App\Shop\Seo\ShareMetaBuilder;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Ajustes de cómo se ve la tienda al compartir su enlace. Guardado inmediato
 * (como el panel del logo): no hay paso de "publicar", así que borrar la imagen
 * anterior en el momento es correcto acá — a diferencia de las imágenes de
 * sección, donde el borrado se difiere hasta guardar. No unificar sin leer el spec.
 */
class LandingShareSettings extends Component
{
    use WithFileUploads;

    public string $title = '';

    public string $description = '';

    public $imageUpload;

    private function authorizeShare(): void
    {
        abort_unless(auth()->user()?->can('shop.landing.manage'), 403);
    }

    public function mount(): void
    {
        $this->authorizeShare();

        $this->title = (string) Setting::get('shop_share_title', '');
        $this->description = (string) Setting::get('shop_share_description', '');
    }

    /** Lo que realmente se va a compartir, con las cadenas de respaldo ya aplicadas. */
    #[Computed]
    public function preview(): ShareMeta
    {
        return app(ShareMetaBuilder::class)->forLanding();
    }

    public function imagePath(): ?string
    {
        return Setting::get('shop_share_image_path') ?: null;
    }

    public function save(): void
    {
        $this->authorizeShare();

        $this->validate([
            'title' => ['nullable', 'string', 'max:70'],
            'description' => ['nullable', 'string', 'max:200'],
        ]);

        Setting::set('shop_share_title', $this->title);
        Setting::set('shop_share_description', $this->description);

        unset($this->preview);
        $this->dispatch('share-settings-saved');
    }

    public function updatedImageUpload(): void
    {
        $this->authorizeShare();

        if (! $this->imageUpload) {
            return;
        }

        $this->validate([
            'imageUpload' => ['image', 'max:2048', 'mimes:png,jpg,jpeg,webp'],
        ]);

        app(LandingImages::class)->delete($this->imagePath());

        $path = LandingUrl::safeStoragePath(app(LandingImages::class)->store($this->imageUpload));
        Setting::set('shop_share_image_path', (string) $path);

        $this->imageUpload = null;
        unset($this->preview);
    }

    public function removeImage(): void
    {
        $this->authorizeShare();

        app(LandingImages::class)->delete($this->imagePath());
        Setting::set('shop_share_image_path', '');

        unset($this->preview);
    }

    public function render()
    {
        return view('livewire.settings.landing-share-settings');
    }
}
```

- [ ] **Step 4: Implementar el blade con la vista previa**

`resources/views/livewire/settings/landing-share-settings.blade.php`:
```blade
<div class="rounded-lg border border-border bg-background p-4 space-y-4">
    <div>
        <h3 class="text-sm font-semibold text-foreground">Cómo se ve al compartir</h3>
        <p class="text-xs text-muted-foreground">
            Lo que aparece cuando alguien pega el enlace de tu tienda en WhatsApp o redes.
            Si dejás los campos vacíos, se usa el nombre del negocio y el texto del héroe.
        </p>
    </div>

    <div class="grid lg:grid-cols-2 gap-4">
        <div class="space-y-3">
            <div>
                <label class="block text-sm font-medium text-foreground mb-1">Título</label>
                <input type="text" wire:model="title" maxlength="70"
                       class="w-full rounded-md border-input bg-background text-sm">
                @error('title') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-foreground mb-1">Descripción</label>
                <textarea wire:model="description" rows="3" maxlength="200"
                          class="w-full rounded-md border-input bg-background text-sm"></textarea>
                @error('description') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-foreground mb-1">Imagen</label>
                <p class="text-xs text-muted-foreground mb-2">
                    Recomendado: 1200 × 630 píxeles, menos de 300 KB. Si no cargás ninguna, se usa
                    el fondo del héroe y, si tampoco hay, el logo.
                </p>
                <input type="file" wire:model="imageUpload" accept="image/png,image/jpeg,image/webp"
                       class="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border file:border-input file:bg-background file:px-3 file:py-1.5 file:text-sm">
                <div wire:loading wire:target="imageUpload" class="text-xs text-blue-600 mt-1">Subiendo…</div>
                @error('imageUpload') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror

                @if($this->imagePath())
                    <x-secondary-button type="button" wire:click="removeImage" class="mt-2">
                        Quitar imagen
                    </x-secondary-button>
                @endif
            </div>

            <div class="flex items-center gap-2">
                <x-primary-button type="button" wire:click="save">Guardar</x-primary-button>
                <span wire:loading wire:target="save" class="text-xs text-muted-foreground">Guardando…</span>
                <span x-data="{ ok: false }" x-on:share-settings-saved.window="ok = true; setTimeout(() => ok = false, 2000)"
                      x-show="ok" x-cloak class="text-xs text-green-600">Guardado</span>
            </div>
        </div>

        {{-- Vista previa: aproximación de cómo lo muestra WhatsApp --}}
        <div>
            <p class="text-xs font-medium text-muted-foreground mb-2">Vista previa</p>
            <div class="max-w-sm rounded-lg border border-border overflow-hidden bg-muted/30">
                @if($this->preview->imageUrl)
                    <img src="{{ $this->preview->imageUrl }}" alt=""
                         class="w-full aspect-[1200/630] object-cover bg-muted">
                @else
                    <div class="w-full aspect-[1200/630] flex items-center justify-center bg-muted text-xs text-muted-foreground">
                        Sin imagen
                    </div>
                @endif
                <div class="p-3">
                    <p class="text-sm font-semibold text-foreground truncate">{{ $this->preview->title }}</p>
                    <p class="text-xs text-muted-foreground line-clamp-2">{{ $this->preview->description }}</p>
                    <p class="text-[11px] text-muted-foreground mt-1 truncate">{{ parse_url($this->preview->url, PHP_URL_HOST) }}</p>
                </div>
            </div>
            <p class="text-xs text-muted-foreground mt-2">
                WhatsApp guarda estas vistas previas por un tiempo: un enlace ya compartido puede seguir
                mostrando la versión anterior.
            </p>
        </div>
    </div>
</div>
```

- [ ] **Step 5: Montarlo en la página del editor**

En `resources/views/settings/landing.blade.php`, agregar el componente ANTES del editor:
```blade
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            <livewire:settings.landing-share-settings />
            <livewire:settings.landing-editor />
        </div>
```

- [ ] **Step 6: Correr — debe pasar**

Run: `php artisan test --filter LandingShareSettingsTest`

- [ ] **Step 7: Regresión del editor**

Run: `php artisan test --filter "LandingEditorAccessTest|LandingEditorTest|LandingSectionFormTest"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/Settings/LandingShareSettings.php resources/views/livewire/settings/landing-share-settings.blade.php resources/views/settings/landing.blade.php tests/Feature/Settings/LandingShareSettingsTest.php
git commit -m "feat(settings): panel de como se ve la tienda al compartir, con vista previa"
```

---

## Cierre

- [ ] **Suite completa**

Run: `php artisan test`
Expected: PASS, sin regresiones.

- [ ] **Verificación manual sugerida (no automatizable)**

Pegar la URL pública de `/tienda` en https://developers.facebook.com/tools/debug/ o en un chat de
WhatsApp y confirmar que aparece título, descripción e imagen. Si no aparece la imagen, lo primero a
revisar es `APP_URL`.

- [ ] **Nota de deploy**

No hay migraciones. Requiere `php artisan cache:clear` (los ajustes se cachean con `rememberForever`).
**`APP_URL` debe ser el dominio público con https** — si apunta a otra cosa, `og:image` y `og:url`
salen mal y las vistas previas no cargan.

---

## Self-review (checklist del autor)

- **Cobertura de spec:** R1 (Task 1), R2 (Task 1), R3 (Task 2), R4 (Task 2), R5 (Tasks 1+2, con test
  dedicado en ambas), R6 (Task 3), R7 (Task 3), R8 (Task 3, con el porqué en el docblock),
  R9 (Task 2 Step 7), R10 (Task 2 Step 4), R11 (Task 3, `authorizeShare()` en cada acción). ✔
- **Sin placeholders:** todo el código va completo. Las dos notas de "usar la factory real del repo"
  son instrucciones de verificación, no código faltante — la aserción está escrita en ambos casos. ✔
- **Consistencia de tipos:** `ShareMeta` con las mismas 6 propiedades en constructor, partial y vista
  previa. `ShareMetaBuilder::{forLanding,forCatalog,forProduct,forCheckout,businessName}` usados igual
  en controladores, partial y componente. `LandingImages::{store,delete}` y
  `LandingUrl::safeStoragePath` con las firmas ya existentes. ✔
- **Riesgo anotado:** el cambio de firma del constructor de `ShopController` puede romper tests que lo
  instancien a mano — Task 2 Step 9 lo cubre explícitamente. ✔
