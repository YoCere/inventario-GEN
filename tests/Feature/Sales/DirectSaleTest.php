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

    public function test_handle_direct_pick_accepts_spoken_number(): void
    {
        $this->stocked('Cargador Samsung 25w', 5000, 3000, 5);
        $this->stocked('Cargador Samsung 45w', 8000, 5000, 5);

        $this->handler->tryQuickSell('555', 'vende 2 cargador samsung a 40');
        $conv = \App\Models\TelegramConversation::where('chat_id', '555')->first();

        $this->handler->handleDirectPick('555', $conv, 'dos'); // voz transcribe "dos"

        $sale = \App\Models\Sale::first();
        $this->assertNotNull($sale);
        $this->assertSame(8000, $sale->total); // 2 × 4000 (segundo)
    }

    public function test_positional_sale_from_pending_photo_list(): void
    {
        // Precio de lista alto (Bs 40) para que el precio pedido (Bs 30) quede por
        // debajo y no dispare el cap de QuickSaleService::sell() (nunca cobra sobre lista).
        $p1 = $this->stocked('Figura Mario', 4000, 1000, 10);
        $p2 = $this->stocked('Figura Luigi', 4000, 1000, 10);

        TelegramConversation::getOrCreate('555')->update([
            'step' => 'busqueda:multiple',
            'data' => ['results' => [
                ['id' => $p1->id, 'name' => 'Figura Mario'],
                ['id' => $p2->id, 'name' => 'Figura Luigi'],
            ]],
            'expires_at' => now()->addMinutes(5),
        ]);

        $handled = $this->handler->tryQuickSell('555', 'vende 3 del segundo a 30');

        $this->assertTrue($handled);
        $sale = Sale::first();
        $this->assertNotNull($sale);
        $this->assertSame(9000, $sale->total); // 3 × 3000
        $this->assertSame(7, $p2->fresh()->quantity); // vendió el SEGUNDO (Luigi)
        $this->assertSame(10, $p1->fresh()->quantity); // el primero intacto
    }

    public function test_positional_out_of_range_no_sale(): void
    {
        $p1 = $this->stocked('Figura Mario', 2000, 1000, 10);
        TelegramConversation::getOrCreate('555')->update([
            'step' => 'busqueda:multiple',
            'data' => ['results' => [['id' => $p1->id, 'name' => 'Figura Mario']]],
            'expires_at' => now()->addMinutes(5),
        ]);

        $handled = $this->handler->tryQuickSell('555', 'vende 1 del quinto');
        $this->assertTrue($handled);
        $this->assertSame(0, Sale::count());
    }

    public function test_positional_without_pending_list_no_sale(): void
    {
        $handled = $this->handler->tryQuickSell('555', 'vende 1 del segundo');
        $this->assertTrue($handled);
        $this->assertSame(0, Sale::count());
    }

    public function test_positional_sale_from_pending_disambiguation_list(): void
    {
        $p1 = $this->stocked('Cargador A', 4000, 1000, 10);
        $p2 = $this->stocked('Cargador B', 4000, 1000, 10);

        \App\Models\TelegramConversation::getOrCreate('555')->update([
            'step' => 'venta_directa:elegir',
            'data' => [
                'ids'         => ['1' => $p1->id, '2' => $p2->id],
                'quantity'    => 1,
                'unit_price'  => null,
                'total_price' => null,
                'method'      => 'cash',
            ],
            'expires_at' => now()->addMinutes(5),
        ]);

        $handled = $this->handler->tryQuickSell('555', 'vende 2 del segundo a 30');

        $this->assertTrue($handled);
        $sale = \App\Models\Sale::first();
        $this->assertNotNull($sale);
        $this->assertSame(6000, $sale->total);          // 2 × 3000
        $this->assertSame(8, $p2->fresh()->quantity);   // vendió el segundo (Cargador B)
        $this->assertSame(10, $p1->fresh()->quantity);  // el primero intacto
    }
}
