<?php

namespace Tests\Feature\Shop;

use App\Models\Product;
use App\Models\Setting;
use App\Shop\Models\LandingSection;
use App\Shop\Seo\ShareMetaBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShareMetaBuilderTest extends TestCase
{
    use RefreshDatabase, EnablesShop;

    protected ShareMetaBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableShop();

        // La migración SP1 (2026_07_08_150100_seed_default_landing_template) siembra
        // secciones por defecto en cada build de RefreshDatabase. Los tests de esta
        // clase controlan explícitamente qué secciones existen, así que arrancamos
        // limpios.
        LandingSection::query()->delete();

        $this->builder = new ShareMetaBuilder();
    }

    private function enabledHero(array $data = []): LandingSection
    {
        return LandingSection::create([
            'type' => 'hero',
            'sort_order' => 0,
            'is_enabled' => true,
            'data' => array_merge(['heading' => 'Bienvenido'], $data),
        ]);
    }

    // --- Landing: título -------------------------------------------------

    public function test_landing_title_falls_back_to_business_name(): void
    {
        Setting::set('shop_business_name', 'Mi Tienda');
        Setting::set('shop_share_title', null);

        $meta = $this->builder->forLanding();

        $this->assertSame('Mi Tienda', $meta->title);
    }

    public function test_landing_title_uses_override_when_present(): void
    {
        Setting::set('shop_business_name', 'Mi Tienda');
        Setting::set('shop_share_title', 'Título compartido personalizado');

        $meta = $this->builder->forLanding();

        $this->assertSame('Título compartido personalizado', $meta->title);
    }

    // --- Landing: descripción (cadena de respaldo) -----------------------

    public function test_landing_description_uses_share_description_override_first(): void
    {
        Setting::set('shop_share_description', 'Descripción para compartir');
        Setting::set('shop_welcome_message', 'Mensaje de bienvenida');
        $this->enabledHero(['subheading' => 'Subtítulo del hero']);

        $meta = $this->builder->forLanding();

        $this->assertSame('Descripción para compartir', $meta->description);
    }

    public function test_landing_description_falls_back_to_enabled_hero_subheading(): void
    {
        Setting::set('shop_share_description', null);
        Setting::set('shop_welcome_message', 'Mensaje de bienvenida');
        $this->enabledHero(['subheading' => 'Subtítulo del hero']);

        $meta = $this->builder->forLanding();

        $this->assertSame('Subtítulo del hero', $meta->description);
    }

    public function test_landing_description_falls_back_to_welcome_message_when_no_hero(): void
    {
        Setting::set('shop_share_description', null);
        Setting::set('shop_welcome_message', 'Mensaje de bienvenida');

        $meta = $this->builder->forLanding();

        $this->assertSame('Mensaje de bienvenida', $meta->description);
    }

    public function test_disabled_hero_is_ignored_for_description(): void
    {
        Setting::set('shop_share_description', null);
        Setting::set('shop_welcome_message', 'Mensaje de bienvenida');

        LandingSection::create([
            'type' => 'hero',
            'sort_order' => 0,
            'is_enabled' => false,
            'data' => ['heading' => 'X', 'subheading' => 'Subtítulo deshabilitado'],
        ]);

        $meta = $this->builder->forLanding();

        $this->assertSame('Mensaje de bienvenida', $meta->description);
    }

    // --- Landing: imagen (cadena de respaldo + URL absoluta) -------------

    public function test_landing_image_uses_share_image_override_first(): void
    {
        Setting::set('shop_share_image_path', 'shop/share.jpg');
        $this->enabledHero(['background_image_path' => 'landing/hero.jpg']);
        Setting::set('shop_logo_path', 'shop/logo.png');

        $meta = $this->builder->forLanding();

        $this->assertNotNull($meta->imageUrl);
        $this->assertStringContainsString('shop/share.jpg', $meta->imageUrl);
    }

    public function test_landing_image_falls_back_to_hero_background(): void
    {
        Setting::set('shop_share_image_path', null);
        $this->enabledHero(['background_image_path' => 'landing/hero.jpg']);
        Setting::set('shop_logo_path', 'shop/logo.png');

        $meta = $this->builder->forLanding();

        $this->assertNotNull($meta->imageUrl);
        $this->assertStringContainsString('landing/hero.jpg', $meta->imageUrl);
    }

    public function test_landing_image_falls_back_to_logo(): void
    {
        Setting::set('shop_share_image_path', null);
        Setting::set('shop_logo_path', 'shop/logo.png');

        $meta = $this->builder->forLanding();

        $this->assertNotNull($meta->imageUrl);
        $this->assertStringContainsString('shop/logo.png', $meta->imageUrl);
    }

    public function test_landing_image_is_null_when_nothing_configured(): void
    {
        Setting::set('shop_share_image_path', null);
        Setting::set('shop_logo_path', null);

        $meta = $this->builder->forLanding();

        $this->assertNull($meta->imageUrl);
    }

    public function test_landing_image_url_is_always_absolute(): void
    {
        Setting::set('shop_share_image_path', 'shop/share.jpg');

        $meta = $this->builder->forLanding();

        $this->assertNotNull($meta->imageUrl);
        $this->assertStringStartsWith('http', $meta->imageUrl);
    }

    // --- Catalog -----------------------------------------------------------

    public function test_catalog_title_contains_business_name_and_url_is_catalog_route(): void
    {
        Setting::set('shop_business_name', 'Mi Tienda');

        $meta = $this->builder->forCatalog();

        $this->assertStringContainsString('Mi Tienda', $meta->title);
        $this->assertSame(route('shop.catalog'), $meta->url);
    }

    // --- Product -------------------------------------------------------------

    private function makeProduct(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'name' => 'Silla Cómoda',
            'description' => '<p>Muy <b>cómodo</b></p>',
        ], $overrides));
    }

    public function test_product_meta_uses_name_and_stripped_description(): void
    {
        $product = $this->makeProduct();

        $meta = $this->builder->forProduct($product);

        $this->assertSame('Silla Cómoda', $meta->title);
        $this->assertSame('Muy cómodo', $meta->description);
        $this->assertSame('product', $meta->type);
        $this->assertSame(route('shop.product', $product->slug), $meta->url);
    }

    public function test_product_with_empty_description_falls_back_to_landing_description(): void
    {
        Setting::set('shop_share_description', 'Descripción de la tienda');

        $product = $this->makeProduct(['description' => '']);

        $meta = $this->builder->forProduct($product);

        $this->assertSame('Descripción de la tienda', $meta->description);
    }

    // --- Checkout: noindex ---------------------------------------------------

    public function test_checkout_is_noindex_and_landing_is_not(): void
    {
        $this->assertTrue($this->builder->forCheckout()->noindex);
        $this->assertFalse($this->builder->forLanding()->noindex);
    }
}
