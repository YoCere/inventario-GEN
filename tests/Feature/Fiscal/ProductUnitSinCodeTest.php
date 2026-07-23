<?php

namespace Tests\Feature\Fiscal;

use App\Livewire\Products\ProductForm;
use App\Livewire\Units\UnitForm;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductUnitSinCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_unit_form_saves_sin_code(): void
    {
        Livewire::test(UnitForm::class)
            ->set('name', 'Pieza')
            ->set('symbol', 'pza')
            ->set('sin_code', '1')
            ->call('save');

        $this->assertSame('1', Unit::where('name', 'Pieza')->firstOrFail()->sin_code);
    }

    public function test_product_form_saves_sin_code(): void
    {
        $category = Category::factory()->create();
        $unit = Unit::factory()->create();

        Livewire::test(ProductForm::class)
            ->set('name', 'Producto Test')
            ->set('category_id', $category->id)
            ->set('unit_id', $unit->id)
            ->set('purchase_price', 1000)
            ->set('selling_price', 1500)
            ->set('quantity', 10)
            ->set('min_stock', 1)
            ->set('sin_code', '87654321')
            ->call('save');

        $this->assertSame('87654321', Product::where('name', 'Producto Test')->firstOrFail()->sin_code);
    }
}
