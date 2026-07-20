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
