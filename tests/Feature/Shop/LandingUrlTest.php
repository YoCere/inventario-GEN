<?php

namespace Tests\Feature\Shop;

use App\Shop\Landing\LandingUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingUrlTest extends TestCase
{
    use RefreshDatabase, EnablesShop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableShop(); // registra route('shop.catalog')
    }

    public function test_catalog_and_empty_resolve_to_catalog_route(): void
    {
        $this->assertSame(route('shop.catalog'), LandingUrl::target('catalog'));
        $this->assertSame(route('shop.catalog'), LandingUrl::target(null));
        $this->assertSame(route('shop.catalog'), LandingUrl::target(''));
    }

    public function test_javascript_scheme_is_rejected(): void
    {
        $this->assertSame(route('shop.catalog'), LandingUrl::target('javascript:alert(1)'));
        $this->assertSame(route('shop.catalog'), LandingUrl::safeUrl('javascript:alert(1)'));
    }

    public function test_valid_http_and_relative_urls_pass(): void
    {
        $this->assertSame('https://example.com', LandingUrl::target('https://example.com'));
        $this->assertSame('/pagina', LandingUrl::safeUrl('/pagina'));
    }

    public function test_whatsapp_builds_wa_me_from_setting(): void
    {
        \App\Models\Setting::set('shop_whatsapp_number', '+591 700-12345');
        $this->assertSame('https://wa.me/59170012345', LandingUrl::target('whatsapp'));
    }

    public function test_unsafe_storage_path_returns_null(): void
    {
        $this->assertNull(LandingUrl::safeStoragePath('x.jpg); } body{color:red'));
        $this->assertNull(LandingUrl::safeStoragePath(null));
        $this->assertSame('shop/hero.jpg', LandingUrl::safeStoragePath('shop/hero.jpg'));
    }

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
}
