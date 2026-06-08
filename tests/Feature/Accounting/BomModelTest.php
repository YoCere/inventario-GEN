<?php

namespace Tests\Feature\Accounting;

use App\Models\BillOfMaterial;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BomModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_bom_with_components(): void
    {
        $pt = Product::factory()->create();
        $mp1 = Product::factory()->create();
        $mp2 = Product::factory()->create();

        $bom = BillOfMaterial::create(['product_id' => $pt->id, 'mod_rate' => 500, 'moi_rate' => 200, 'cif_rate' => 300]);
        $bom->components()->create(['component_product_id' => $mp1->id, 'quantity_per_unit' => 2]);
        $bom->components()->create(['component_product_id' => $mp2->id, 'quantity_per_unit' => 1]);

        $this->assertEquals(2, $bom->components()->count());
        $this->assertEquals(500, $bom->fresh()->mod_rate);
        $this->assertTrue($bom->is_active);
    }
}
