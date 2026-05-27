<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProductSearchCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_inactive_products_not_returned_in_search(): void
    {
        $active   = Product::factory()->create(['name' => 'Producto Activo Test', 'is_active' => true, 'quantity' => 10]);
        $inactive = Product::factory()->create(['name' => 'Producto Inactivo Test', 'is_active' => false, 'quantity' => 10]);

        $user = User::factory()->staff()->create();

        $response = $this->actingAs($user)
            ->postJson(route('ajax.products.search'), ['q' => 'Producto', 'limit' => 50]);

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertContains($active->id, $ids->all());
        $this->assertNotContains($inactive->id, $ids->all());
    }

    public function test_cache_key_differs_for_different_queries(): void
    {
        $key1 = 'products_search_v2_' . md5('Zapato|50');
        $key2 = 'products_search_v2_' . md5('Camisa|50');
        $this->assertNotEquals($key1, $key2);
    }

    public function test_search_requires_authentication(): void
    {
        $response = $this->postJson(route('ajax.products.search'), ['q' => 'test']);
        $response->assertUnauthorized();
    }
}
