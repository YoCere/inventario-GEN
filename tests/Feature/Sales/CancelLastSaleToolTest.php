<?php

namespace Tests\Feature\Sales;

use App\Enums\PaymentMethod;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Agent\AgentContext;
use App\Services\Agent\Tools\CancelLastSaleTool;
use App\Services\QuickSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelLastSaleToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            \Database\Seeders\AccountingPeriodSeeder::class,
            \Database\Seeders\ChartOfAccountSeeder::class,
            \Database\Seeders\SettingSeeder::class,
        ]);
    }

    public function test_cancels_last_sale_and_restores_stock(): void
    {
        $seller = User::factory()->staff()->create();
        $warehouse = Warehouse::create(['name' => 'Almacén', 'code' => 'ALM']);
        $location = Location::create(['name' => 'Estante', 'code' => 'EST', 'warehouse_id' => $warehouse->id]);
        $product = Product::factory()->create(['selling_price' => 2000, 'purchase_price' => 1000, 'quantity' => 0]);
        ProductStock::create(['product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 10]);
        $product->update(['quantity' => 10]);

        app(QuickSaleService::class)->sell($product, 4, null, PaymentMethod::CASH, 0, $seller->id);
        $this->assertSame(6, $product->fresh()->quantity);

        $tool = app(CancelLastSaleTool::class);
        $result = $tool->execute([], new AgentContext($seller, '555', 'telegram'));

        $this->assertTrue($result['ok']);
        $this->assertSame(10, $product->fresh()->quantity);
    }

    public function test_error_when_no_recent_sale(): void
    {
        $seller = User::factory()->staff()->create();
        $tool = app(CancelLastSaleTool::class);

        $result = $tool->execute([], new AgentContext($seller, '555', 'telegram'));

        $this->assertArrayHasKey('error', $result);
    }
}
