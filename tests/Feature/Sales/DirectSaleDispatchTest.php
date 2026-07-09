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
use App\Services\Telegram\BotHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DirectSaleDispatchTest extends TestCase
{
    use RefreshDatabase;

    private Location $loc;

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
        $telegram->shouldReceive('sendChatAction')->andReturn([]);
        $this->app->instance(TelegramService::class, $telegram);

        $seller = User::factory()->staff()->create();
        // Autenticar el chat 555: isAuthenticated exige TelegramUser con user asociado y last_login reciente.
        TelegramUser::create([
            'chat_id' => '555', 'user_id' => $seller->id, 'identifier' => 'alice', 'last_login' => now(),
        ]);

        $wh = Warehouse::create(['name' => 'Almacén', 'code' => 'ALM']);
        $this->loc = Location::create(['name' => 'Estante', 'code' => 'EST', 'warehouse_id' => $wh->id]);
    }

    private function mk(string $name): Product
    {
        $p = Product::factory()->create([
            'name' => $name, 'selling_price' => 4000, 'purchase_price' => 1000, 'quantity' => 0, 'is_active' => true,
        ]);
        ProductStock::create(['product_id' => $p->id, 'location_id' => $this->loc->id, 'quantity' => 10]);
        $p->update(['quantity' => 10]);
        return $p;
    }

    private function pendingList(Product $p1, Product $p2, Product $p3): void
    {
        TelegramConversation::getOrCreate('555')->update([
            'step' => 'venta_directa:elegir',
            'data' => ['ids' => ['1' => $p1->id, '2' => $p2->id, '3' => $p3->id],
                       'quantity' => 1, 'unit_price' => null, 'total_price' => null, 'method' => 'cash'],
            'expires_at' => now()->addMinutes(5),
        ]);
    }

    private function dispatchText(string $text): void
    {
        app(BotHandler::class)->dispatch(['message' => ['from' => ['id' => 555], 'text' => $text]]);
    }

    public function test_positional_command_while_pending_list_sells_right_product_via_dispatch(): void
    {
        $p1 = $this->mk('Cargador A'); $p2 = $this->mk('Cargador B'); $p3 = $this->mk('Cargador C');
        $this->pendingList($p1, $p2, $p3);

        $this->dispatchText('vende 2 del tercero a 20');

        $sale = Sale::first();
        $this->assertNotNull($sale, 'Debió venderse algo');
        $this->assertSame(4000, $sale->total);          // 2 × 2000 (Bs 20)
        $this->assertSame(8, $p3->fresh()->quantity);    // vendió el TERCERO, qty 2
        $this->assertSame(10, $p2->fresh()->quantity);   // NO el segundo
    }

    public function test_name_command_clears_stale_pending_list(): void
    {
        $p1 = $this->mk('Cargador A'); $p2 = $this->mk('Cargador B'); $p3 = $this->mk('Cargador C');
        $mouse = $this->mk('Mouse Gamer Unico');
        $this->pendingList($p1, $p2, $p3);

        // Orden por NOMBRE de un producto no relacionado: vende el mouse y DEBE limpiar la lista.
        $this->dispatchText('vende 1 mouse gamer unico a 10');
        $this->assertSame(1, Sale::count());
        $this->assertSame(9, $mouse->fresh()->quantity);
        $this->assertNull(
            TelegramConversation::where('chat_id', '555')->whereIn('step', ['venta_directa:elegir', 'busqueda:multiple'])->first(),
            'La lista pendiente debió limpiarse tras la venta por nombre'
        );

        // Un "3" posterior NO debe vender de la lista vieja (ya no existe).
        $this->dispatchText('3');
        $this->assertSame(1, Sale::count(), 'No debió haber una segunda venta desde la lista rancia');
        $this->assertSame(10, $p3->fresh()->quantity); // el tercero intacto
    }
}
