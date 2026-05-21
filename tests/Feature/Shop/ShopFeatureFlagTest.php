<?php

namespace Tests\Feature\Shop;

use App\Models\Setting;
use App\Shop\Services\ShopFeatureFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopFeatureFlagTest extends TestCase
{
    use RefreshDatabase, EnablesShop;

    public function test_catalog_returns_404_when_flag_off(): void
    {
        // No llamamos enableShop() — rutas /tienda no están registradas con flag off.
        Setting::set('shop_enabled', '0');
        app(ShopFeatureFlag::class)->invalidate();

        $this->get('/tienda')->assertNotFound();
        $this->get('/tienda/api/search?q=test')->assertNotFound();
        $this->post('/tienda/reservar', [])->assertNotFound();
    }

    public function test_catalog_index_loads_when_flag_on(): void
    {
        $this->enableShop();

        $this->get('/tienda')->assertOk();
    }

    public function test_search_endpoint_responds_when_flag_on(): void
    {
        $this->enableShop();

        $this->getJson('/tienda/api/search?q=ab')
            ->assertOk()
            ->assertJsonStructure(['query', 'results']);
    }
}
