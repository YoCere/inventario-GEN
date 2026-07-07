<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Shop\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductImageUrlTest extends TestCase
{
    use RefreshDatabase;

    private function primaryImage(Product $product): ProductImage
    {
        return ProductImage::create([
            'product_id' => $product->id,
            'path'       => "products/{$product->id}/uuid_full.webp",
            'path_full'  => "products/{$product->id}/uuid_full.webp",
            'path_card'  => "products/{$product->id}/uuid_card.webp",
            'path_thumb' => "products/{$product->id}/uuid_thumb.webp",
            'is_primary' => true,
            'sort_order' => 0,
        ]);
    }

    public function test_image_url_prefers_gallery_over_stale_legacy_path(): void
    {
        // Bug real: producto migrado a galería pero con image_path .jpg viejo/muerto.
        $product = Product::factory()->create(['image_path' => 'products/stale-old.jpg']);
        $this->primaryImage($product);

        $url = $product->fresh()->image_url;

        $this->assertStringContainsString('uuid_full.webp', $url);
        $this->assertStringNotContainsString('stale-old.jpg', $url);
    }

    public function test_image_url_uses_gallery_when_legacy_path_null(): void
    {
        $product = Product::factory()->create(['image_path' => null]);
        $this->primaryImage($product);

        $url = $product->fresh()->image_url;

        $this->assertStringContainsString('uuid_full.webp', $url);
        $this->assertStringNotContainsString('placeholder', $url);
    }

    public function test_image_url_falls_back_to_legacy_path_without_gallery(): void
    {
        $product = Product::factory()->create(['image_path' => 'products/legacy.jpg']);

        $this->assertStringContainsString('legacy.jpg', $product->image_url);
    }

    public function test_image_url_placeholder_when_no_image(): void
    {
        $product = Product::factory()->create(['image_path' => null]);

        $this->assertStringContainsString('placeholder', $product->image_url);
    }

    public function test_has_display_image_true_with_gallery_only(): void
    {
        $product = Product::factory()->create(['image_path' => null]);
        $this->primaryImage($product);

        $this->assertTrue($product->fresh()->hasDisplayImage());
    }

    public function test_has_display_image_false_without_any_image(): void
    {
        $product = Product::factory()->create(['image_path' => null]);

        $this->assertFalse($product->hasDisplayImage());
    }
}
