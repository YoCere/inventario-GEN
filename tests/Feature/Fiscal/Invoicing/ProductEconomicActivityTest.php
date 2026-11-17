<?php

namespace Tests\Feature\Fiscal\Invoicing;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductEconomicActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_stores_economic_activity(): void
    {
        $p = Product::factory()->create(['economic_activity' => '477300']);

        $this->assertSame('477300', $p->fresh()->economic_activity);
    }

    public function test_economic_activity_is_optional(): void
    {
        $p = Product::factory()->create();

        $this->assertNull($p->fresh()->economic_activity);
    }
}
