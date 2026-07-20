<?php

namespace Tests\Feature\Shop;

use App\Models\Product;
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

    public function test_landing_emits_core_share_tags(): void
    {
        $response = $this->get('/tienda');

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('property="og:title"', $html);
        $this->assertStringContainsString('property="og:description"', $html);
        $this->assertStringContainsString('property="og:url"', $html);
        $this->assertStringContainsString('name="twitter:card"', $html);
        $this->assertStringContainsString('rel="canonical"', $html);
    }

    public function test_landing_og_image_is_absolute(): void
    {
        Setting::set('shop_share_image_path', 'shop/landing/share.jpg');

        $response = $this->get('/tienda');
        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression('/property="og:image" content="([^"]+)"/', $html);
        preg_match('/property="og:image" content="([^"]+)"/', $html, $matches);

        $this->assertNotEmpty($matches[1] ?? null);
        $this->assertStringStartsWith('http', $matches[1]);
    }

    public function test_landing_og_image_omitted_when_no_image_configured(): void
    {
        Setting::set('shop_share_image_path', null);
        Setting::set('shop_logo_path', null);
        LandingSection::query()->delete();

        $response = $this->get('/tienda');
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('property="og:image"', $html);
    }

    public function test_catalog_emits_og_title(): void
    {
        $response = $this->get('/tienda/catalogo');

        $response->assertOk();
        $this->assertStringContainsString('property="og:title"', $response->getContent());
    }

    public function test_checkout_emits_noindex_robots(): void
    {
        $response = $this->get('/tienda/checkout');

        $response->assertOk();
        $this->assertStringContainsString('name="robots"', $response->getContent());
    }

    public function test_custom_share_title_appears_on_landing(): void
    {
        Setting::set('shop_share_title', 'Marcador Único De Prueba 12345');

        $response = $this->get('/tienda');

        $response->assertOk();
        $this->assertStringContainsString('Marcador Único De Prueba 12345', $response->getContent());
    }

    public function test_share_title_is_escaped_in_html(): void
    {
        Setting::set('shop_share_title', 'Título con "comillas" y <b>negrita</b>');

        $response = $this->get('/tienda');
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('<b>negrita</b>', $html);
        $this->assertStringNotContainsString('content="Título con "comillas"', $html);
    }

    public function test_logo_present_yields_favicon_link(): void
    {
        Setting::set('shop_logo_path', 'shop/logo.png');

        $response = $this->get('/tienda');
        $response->assertOk();
        $this->assertStringContainsString('rel="icon"', $response->getContent());
    }

    public function test_product_page_emits_og_title_exactly_once(): void
    {
        $product = Product::factory()->public(10)->create([
            'name' => 'Silla Cómoda',
            'description' => '<p>Muy <b>cómoda</b></p>',
        ]);

        $response = $this->get('/tienda/producto/' . $product->slug);

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'property="og:title"'));
    }
}
