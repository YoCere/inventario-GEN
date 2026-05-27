<?php

namespace Tests\Feature\Authorization;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            AccountingPeriodSeeder::class,
            ChartOfAccountSeeder::class,
            SettingSeeder::class,
        ]);
    }

    private function makePendingSale(User $creator): Sale
    {
        return Sale::create([
            'invoice_number' => 'INV.260526.' . str_pad(rand(1,9999), 6, '0', STR_PAD_LEFT),
            'created_by' => $creator->id,
            'sale_date' => now(),
            'status' => SaleStatus::PENDING,
            'payment_method' => 'cash',
            'subtotal' => 10000,
            'total_discount' => 0,
            'total' => 10000,
            'cash_received' => 0,
            'change' => 0,
            'global_discount' => 0,
            'source' => 'pos',
        ]);
    }

    public function test_staff_cannot_complete_another_users_sale(): void
    {
        $owner = User::factory()->staff()->create();
        $other = User::factory()->staff()->create();
        $sale  = $this->makePendingSale($owner);

        $response = $this->actingAs($other)
            ->patch(route('sales.complete', $sale), ['cash_received' => 10000]);

        $response->assertForbidden();
        $this->assertEquals(SaleStatus::PENDING, $sale->fresh()->status);
    }

    public function test_staff_can_complete_own_sale(): void
    {
        $owner = User::factory()->staff()->create();
        $sale  = $this->makePendingSale($owner);

        $response = $this->actingAs($owner)
            ->patch(route('sales.complete', $sale), ['cash_received' => 10000]);

        $response->assertRedirect();
        $this->assertEquals(SaleStatus::COMPLETED, $sale->fresh()->status);
    }

    public function test_admin_can_complete_any_sale(): void
    {
        $owner = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();
        $sale  = $this->makePendingSale($owner);

        $response = $this->actingAs($admin)
            ->patch(route('sales.complete', $sale), ['cash_received' => 10000]);

        $response->assertRedirect();
        $this->assertEquals(SaleStatus::COMPLETED, $sale->fresh()->status);
    }

    public function test_staff_cannot_cancel_completed_sale(): void
    {
        $owner = User::factory()->staff()->create();
        $sale  = $this->makePendingSale($owner);
        $sale->update(['status' => SaleStatus::COMPLETED]);

        $response = $this->actingAs($owner)
            ->delete(route('sales.destroy', $sale));

        $response->assertForbidden();
        $this->assertEquals(SaleStatus::COMPLETED, $sale->fresh()->status);
    }

    public function test_staff_can_cancel_own_pending_sale(): void
    {
        $owner = User::factory()->staff()->create();
        $sale  = $this->makePendingSale($owner);

        $response = $this->actingAs($owner)
            ->delete(route('sales.destroy', $sale));

        $response->assertRedirect();
        $this->assertEquals(SaleStatus::CANCELLED, $sale->fresh()->status);
    }

    public function test_staff_cannot_cancel_another_users_pending_sale(): void
    {
        $owner = User::factory()->staff()->create();
        $other = User::factory()->staff()->create();
        $sale  = $this->makePendingSale($owner);

        $response = $this->actingAs($other)
            ->delete(route('sales.destroy', $sale));

        $response->assertForbidden();
        $this->assertEquals(SaleStatus::PENDING, $sale->fresh()->status);
    }

    public function test_admin_can_cancel_any_completed_sale(): void
    {
        $owner = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();
        $sale  = $this->makePendingSale($owner);
        $sale->update(['status' => SaleStatus::COMPLETED]);

        $response = $this->actingAs($admin)
            ->delete(route('sales.destroy', $sale));

        $response->assertRedirect();
        $this->assertEquals(SaleStatus::CANCELLED, $sale->fresh()->status);
    }
}
