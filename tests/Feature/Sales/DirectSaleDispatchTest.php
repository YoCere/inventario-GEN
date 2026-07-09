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

    public function test_positional_command_while_pending_list_sells_right_product_via_dispatch(): void
    {
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
        // Autenticar el chat 555: isAuthenticated exige TelegramUser con user asociado
        // y last_login reciente (dentro de SESSION_TTL_HOURS).
        TelegramUser::create([
            'chat_id' => '555', 'user_id' => $seller->id, 'identifier' => 'alice', 'last_login' => now(),
        ]);

        $wh = Warehouse::create(['name' => 'Almacén', 'code' => 'ALM']);
        $loc = Location::create(['name' => 'Estante', 'code' => 'EST', 'warehouse_id' => $wh->id]);
        $mk = function (string $name) use ($loc) {
            $p = Product::factory()->create(['name' => $name, 'selling_price' => 4000, 'purchase_price' => 1000, 'quantity' => 0, 'is_active' => true]);
            ProductStock::create(['product_id' => $p->id, 'location_id' => $loc->id, 'quantity' => 10]);
            $p->update(['quantity' => 10]);
            return $p;
        };
        $p1 = $mk('Cargador A'); $p2 = $mk('Cargador B'); $p3 = $mk('Cargador C');

        TelegramConversation::getOrCreate('555')->update([
            'step' => 'venta_directa:elegir',
            'data' => ['ids' => ['1' => $p1->id, '2' => $p2->id, '3' => $p3->id],
                       'quantity' => 1, 'unit_price' => null, 'total_price' => null, 'method' => 'cash'],
            'expires_at' => now()->addMinutes(5),
        ]);

        app(BotHandler::class)->dispatch(['message' => ['from' => ['id' => 555], 'text' => 'vende 2 del tercero a 20']]);

        $sale = Sale::first();
        $this->assertNotNull($sale, 'Debió venderse algo');
        $this->assertSame(4000, $sale->total);          // 2 × 2000 (Bs 20)
        $this->assertSame(8, $p3->fresh()->quantity);    // vendió el TERCERO, qty 2
        $this->assertSame(10, $p2->fresh()->quantity);   // NO el segundo
    }
}
