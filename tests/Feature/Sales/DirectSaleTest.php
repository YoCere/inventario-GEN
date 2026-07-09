<?php

namespace Tests\Feature\Sales;

use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\TelegramConversation;
use App\Models\TelegramUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Messaging\TelegramService;
use App\Services\Telegram\BotSaleHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DirectSaleTest extends TestCase
{
    use RefreshDatabase;

    private BotSaleHandler $handler;
    private Location $location;
    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            \Database\Seeders\AccountingPeriodSeeder::class,
            \Database\Seeders\ChartOfAccountSeeder::class,
            \Database\Seeders\SettingSeeder::class,
        ]);

        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->andReturn([]);
        $this->app->instance(TelegramService::class, $telegram);

        $this->handler = app(BotSaleHandler::class);

        $this->seller = User::factory()->staff()->create();
        TelegramUser::create(['chat_id' => '555', 'user_id' => $this->seller->id, 'identifier' => 'alice']);

        $warehouse = Warehouse::create(['name' => 'Almacén', 'code' => 'ALM']);
        $this->location = Location::create(['name' => 'Estante', 'code' => 'EST', 'warehouse_id' => $warehouse->id]);
    }

    private function stocked(string $name, int $selling, int $purchase, int $stock): Product
    {
        $p = Product::factory()->create([
            'name' => $name, 'selling_price' => $selling, 'purchase_price' => $purchase, 'quantity' => 0, 'is_active' => true,
        ]);
        ProductStock::create(['product_id' => $p->id, 'location_id' => $this->location->id, 'quantity' => $stock]);
        $p->update(['quantity' => $stock]);
        return $p;
    }

    public function test_non_sale_message_returns_false(): void
    {
        $this->assertFalse($this->handler->tryQuickSell('555', 'hola'));
    }

    public function test_direct_sale_by_name_single_match(): void
    {
        $p = $this->stocked('Figura Mario', 2000, 1000, 10);

        $handled = $this->handler->tryQuickSell('555', 'vende 3 figura mario a 10');

        $this->assertTrue($handled);
        $sale = Sale::first();
        $this->assertNotNull($sale);
        $this->assertSame(3000, $sale->total);
        $this->assertSame(7, $p->fresh()->quantity);
    }

    public function test_ambiguous_by_name_sets_pending_and_number_completes(): void
    {
        $this->stocked('Cargador Samsung 25w', 5000, 3000, 5);
        $this->stocked('Cargador Samsung 45w', 8000, 5000, 5);

        $handled = $this->handler->tryQuickSell('555', 'vende 2 cargador samsung a 40');
        $this->assertTrue($handled);
        $this->assertSame(0, Sale::count());

        $conv = TelegramConversation::where('chat_id', '555')->first();
        $this->assertSame('venta_directa:elegir', $conv->step);

        $this->handler->handleDirectPick('555', $conv, '2');
        $sale = Sale::first();
        $this->assertNotNull($sale);
        $this->assertSame(8000, $sale->total);
    }

    public function test_not_found_message_no_sale(): void
    {
        $handled = $this->handler->tryQuickSell('555', 'vende 1 productoinexistentexyz');
        $this->assertTrue($handled);
        $this->assertSame(0, Sale::count());
    }
}
