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
        $user = User::factory()->create();
        $hot = Product::factory()->create(['name' => 'Caliente', 'is_active' => true]);
        $cold = Product::factory()->create(['name' => 'Frio', 'is_active' => true]);

        $sale = Sale::create([
            'invoice_number' => 'INV-0001',
            'created_by' => $user->id,
            'sale_date' => now(),
            'status' => 'completed',
            'subtotal' => 1100,
            'total' => 1100,
        ]);
        SaleItem::create(['sale_id' => $sale->id, 'product_id' => $hot->id, 'quantity' => 10, 'subtotal' => 1000, 'cost_price' => 50, 'unit_price' => 100, 'final_price' => 100]);
        SaleItem::create(['sale_id' => $sale->id, 'product_id' => $cold->id, 'quantity' => 1, 'subtotal' => 100, 'cost_price' => 50, 'unit_price' => 100, 'final_price' => 100]);

        $tool = new GetSlowSellersTool();
        $result = $tool->execute(['days' => 30, 'limit' => 1], new AgentContext(null, 'web:1', 'web'));

        $this->assertSame('Frio', $result['slow'][0]['name']);
        $this->assertSame(1, $result['slow'][0]['units_sold']);
    }

    public function test_requires_sales_view_permission(): void
    {
        $this->assertSame('sales.view', (new GetSlowSellersTool())->requiredPermission());
    }
}
