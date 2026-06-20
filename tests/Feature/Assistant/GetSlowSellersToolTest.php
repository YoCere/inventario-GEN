<?php

namespace Tests\Feature\Assistant;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Agent\AgentContext;
use App\Services\Agent\Tools\GetSlowSellersTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetSlowSellersToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_products_with_least_sales(): void
    {
        $hot = Product::factory()->create(['name' => 'Caliente', 'is_active' => true]);
        $cold = Product::factory()->create(['name' => 'Frio', 'is_active' => true]);
        $dead = Product::factory()->create(['name' => 'Muerto', 'is_active' => true]); // zero sales

        $user = User::factory()->create();
        $sale = Sale::create([
            'sale_date' => now(),
            'status' => 'completed',
            'total' => 1000,
            'subtotal' => 1000,
            'invoice_number' => 'INV-SLOW-1',
            'created_by' => $user->id,
        ]);
        SaleItem::create(['sale_id' => $sale->id, 'product_id' => $hot->id, 'quantity' => 10, 'subtotal' => 1000, 'cost_price' => 50, 'unit_price' => 100, 'final_price' => 100]);
        SaleItem::create(['sale_id' => $sale->id, 'product_id' => $cold->id, 'quantity' => 1, 'subtotal' => 100, 'cost_price' => 50, 'unit_price' => 100, 'final_price' => 100]);

        $tool = new GetSlowSellersTool();
        $result = $tool->execute(['days' => 30, 'limit' => 2], new AgentContext(null, 'web:1', 'web'));

        // zero-sale product is the slowest → ranks first with 0 units
        $this->assertSame('Muerto', $result['slow'][0]['name']);
        $this->assertSame(0, $result['slow'][0]['units_sold']);
        $this->assertSame('Frio', $result['slow'][1]['name']);
        $this->assertSame(1, $result['slow'][1]['units_sold']);
    }

    public function test_requires_sales_view_permission(): void
    {
        $this->assertSame('sales.view', (new GetSlowSellersTool())->requiredPermission());
    }
}
