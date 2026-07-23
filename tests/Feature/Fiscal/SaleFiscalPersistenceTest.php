<?php

namespace Tests\Feature\Fiscal;

use App\DTOs\SaleData;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Fiscal\SaleTaxCalculator;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SaleService;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleFiscalPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private SaleService $service;
    private User $seller;
    private Product $product;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            AccountingPeriodSeeder::class,
            ChartOfAccountSeeder::class,
            SettingSeeder::class,
        ]);

        $this->service = app(SaleService::class);
        $this->seller = User::factory()->staff()->create();

        $warehouse = Warehouse::create(['name' => 'Almacén', 'code' => 'ALM']);
        $this->location = Location::create(['name' => 'Estante', 'code' => 'EST', 'warehouse_id' => $warehouse->id]);

        $this->product = Product::factory()->create([
            'selling_price'  => 10000,
            'purchase_price' => 6000,
            'quantity'       => 0,
        ]);

        ProductStock::create([
            'product_id'  => $this->product->id,
            'location_id' => $this->location->id,
            'quantity'    => 20,
        ]);
        $this->product->update(['quantity' => 20]);
    }

    private function makeSaleData(array $overrides = []): SaleData
    {
        return SaleData::fromArray(array_merge([
            'customer_id'     => null,
            'buyer_name'      => null,
            'buyer_phone'     => null,
            'created_by'      => $this->seller->id,
            'sale_date'       => now()->toDateTimeString(),
            'status'          => SaleStatus::COMPLETED->value,
            'payment_method'  => PaymentMethod::CASH->value,
            'source'          => 'pos',
            'notes'           => null,
            'cash_received'   => 20000,
            'change'          => 0,
            'global_discount' => 0,
            'items'           => [
                [
                    'product_id' => $this->product->id,
                    'quantity'   => 2,
                    'unit_price' => 10000,
                    'discount'   => 0,
                ],
            ],
        ], $overrides));
    }

    public function test_sale_persists_fiscal_breakdown(): void
    {
        Setting::set('tax_iva_rate', '13');
        Setting::set('tax_it_rate', '3');

        $sale = $this->service->createSale($this->makeSaleData());

        // total = 2 * 10000 = 20000 (no discounts)
        $this->assertEquals(20000, $sale->total);

        $expected = (new SaleTaxCalculator())->forTotal($sale->total);

        $fresh = $sale->fresh();
        $this->assertSame($expected['taxable_base'], $fresh->taxable_base);
        $this->assertSame($expected['iva_amount'], $fresh->iva_amount);
        $this->assertSame($expected['it_amount'], $fresh->it_amount);
        $this->assertSame(20000, $fresh->taxable_base);
        $this->assertSame(2600, $fresh->iva_amount);
        $this->assertSame(600, $fresh->it_amount);
    }

    public function test_quick_sale_without_invoice_still_works(): void
    {
        // No customer, no wants_invoice key at all -> defaults to false, quick-sale flow untouched.
        $sale = $this->service->createSale($this->makeSaleData([
            'customer_id' => null,
        ]));

        $this->assertEquals(SaleStatus::COMPLETED, $sale->status);
        $this->assertEquals(20000, $sale->total);
        $this->assertFalse($sale->fresh()->wants_invoice);
    }
}
