<?php

namespace Tests\Feature\Products;

use App\Livewire\Products\ProductTable;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductTableBulkPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_set_exact_selling_price_on_selected(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $a = Product::factory()->create(['selling_price' => 500]);
        $b = Product::factory()->create(['selling_price' => 700]);
        $c = Product::factory()->create(['selling_price' => 900]); // no seleccionado

        Livewire::test(ProductTable::class)
            ->set('checkboxValues', [(string) $a->id, (string) $b->id])
            ->set('bulkTarget', 'selling')
            ->set('bulkMode', 'set')
            ->set('bulkValue', 10) // 10.00 → 1000 céntimos
            ->call('applyBulkPrice');

        $this->assertSame(1000, $a->fresh()->selling_price);
        $this->assertSame(1000, $b->fresh()->selling_price);
        $this->assertSame(900, $c->fresh()->selling_price); // intacto
    }

    public function test_increase_percentage_on_selected(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $a = Product::factory()->create(['selling_price' => 1000]);

        Livewire::test(ProductTable::class)
            ->set('checkboxValues', [(string) $a->id])
            ->set('bulkTarget', 'selling')
            ->set('bulkMode', 'inc_pct')
            ->set('bulkValue', 10) // +10%
            ->call('applyBulkPrice');

        $this->assertSame(1100, $a->fresh()->selling_price);
    }

    public function test_decrease_amount_on_purchase_price(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $a = Product::factory()->create(['purchase_price' => 1000]);

        Livewire::test(ProductTable::class)
            ->set('checkboxValues', [(string) $a->id])
            ->set('bulkTarget', 'purchase')
            ->set('bulkMode', 'dec_amt')
            ->set('bulkValue', 3) // -3.00 Bs → -300 céntimos
            ->call('applyBulkPrice');

        $this->assertSame(700, $a->fresh()->purchase_price);
    }
}
