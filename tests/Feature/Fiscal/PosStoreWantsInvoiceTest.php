<?php

namespace Tests\Feature\Fiscal;

use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosStoreWantsInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            AccountingPeriodSeeder::class,
            ChartOfAccountSeeder::class,
            SettingSeeder::class,
        ]);

        $this->seller = User::factory()->staff()->create();

        $warehouse = Warehouse::create(['name' => 'Almacén', 'code' => 'ALM']);
        $location = Location::create(['name' => 'Estante', 'code' => 'EST', 'warehouse_id' => $warehouse->id]);

        $this->product = Product::factory()->create([
            'selling_price'  => 10000,
            'purchase_price' => 6000,
            'quantity'       => 0,
        ]);

        ProductStock::create([
            'product_id'  => $this->product->id,
            'location_id' => $location->id,
            'quantity'    => 20,
        ]);
        $this->product->update(['quantity' => 20]);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'sale_date' => now()->toDateTimeString(),
            'payment_method' => 'cash',
            'status' => 'completed',
            'cash_received' => 10000,
            'change' => 0,
            'global_discount' => 0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => 10000,
                    'discount' => 0,
                ],
            ],
        ], $overrides);
    }

    public function test_store_persists_wants_invoice_with_identified_customer(): void
    {
        $customer = Customer::factory()->create([
            'doc_type' => '4',
            'doc_number' => '1234567',
        ]);

        $response = $this->actingAs($this->seller)
            ->postJson(route('sales.store'), $this->basePayload([
                'customer_id' => $customer->id,
                'wants_invoice' => 1,
            ]));

        $response->assertCreated();
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('sales', [
            'customer_id' => $customer->id,
            'wants_invoice' => true,
        ]);
    }

    public function test_wants_invoice_requires_customer_with_identity(): void
    {
        $response = $this->actingAs($this->seller)
            ->postJson(route('sales.store'), $this->basePayload([
                'customer_id' => null,
                'wants_invoice' => 1,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('wants_invoice');

        $this->assertDatabaseMissing('sales', [
            'wants_invoice' => true,
        ]);
    }

    public function test_wants_invoice_rejected_when_customer_lacks_identity(): void
    {
        // Cliente existe pero SIN NIT/CI cargado → no se puede facturar.
        $customer = Customer::factory()->create(['doc_type' => null, 'doc_number' => null]);

        $response = $this->actingAs($this->seller)
            ->postJson(route('sales.store'), $this->basePayload([
                'customer_id' => $customer->id,
                'wants_invoice' => 1,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('wants_invoice');
        $this->assertDatabaseMissing('sales', ['wants_invoice' => true]);
    }
}
