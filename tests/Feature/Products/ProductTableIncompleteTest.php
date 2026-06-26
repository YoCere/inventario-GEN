<?php

namespace Tests\Feature\Products;

use App\Livewire\Products\ProductTable;
use App\Models\Product;
use App\Shop\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductTableIncompleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_missing_price_filters_products_with_selling_price(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $withPrice = Product::factory()->create(['selling_price' => 5000]);
        $noPrice   = Product::factory()->create(['selling_price' => 0]);

        Livewire::test(ProductTable::class)
            ->set('onlyMissingPrice', true)
            ->assertSee($noPrice->sku)
            ->assertDontSee($withPrice->sku);
    }

    public function test_only_missing_photo_filters_products_without_images(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $withPhoto = Product::factory()->create();
        ProductImage::create([
            'product_id' => $withPhoto->id, 'path' => 'p.webp',
            'path_thumb' => 't.webp', 'path_card' => 'c.webp', 'path_full' => 'f.webp',
            'sort_order' => 0, 'is_primary' => true,
        ]);
        $noPhoto = Product::factory()->create();

        Livewire::test(ProductTable::class)
            ->set('onlyMissingPhoto', true)
            ->assertSee($noPhoto->sku)
            ->assertDontSee($withPhoto->sku);
    }
}
