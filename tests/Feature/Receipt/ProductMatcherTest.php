<?php

namespace Tests\Feature\Receipt;

use App\Models\Product;
use App\Services\Receipt\ProductMatcher;
use App\Services\Receipt\ReceiptData;
use App\Services\Receipt\ReceiptLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_existing_product_and_separates_unmatched(): void
    {
        $coca = Product::factory()->create(['name' => 'Coca Cola 2L', 'sku' => 'CC2L', 'is_active' => true]);

        $data = new ReceiptData('2026-06-20', null, [
            new ReceiptLine('Coca Cola 2L', 12, 150000),
            new ReceiptLine('Producto Inexistente XYZ', 5, 80000),
        ]);

        $result = app(ProductMatcher::class)->match($data);

        $this->assertCount(1, $result['matched']);
        $this->assertSame($coca->id, $result['matched'][0]['product_id']);
        $this->assertSame(12, $result['matched'][0]['quantity']);
        $this->assertSame(150000, $result['matched'][0]['unit_price']);

        $this->assertCount(1, $result['unmatched']);
        $this->assertSame('Producto Inexistente XYZ', $result['unmatched'][0]['raw_name']);
    }
}
