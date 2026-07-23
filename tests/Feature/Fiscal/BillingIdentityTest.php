<?php

namespace Tests\Feature\Fiscal;

use App\Fiscal\BillingIdentity;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_holds_identity_columns(): void
    {
        $c = Customer::create([
            'name' => 'Juan', 'doc_type' => '1', 'doc_number' => '5115889',
            'doc_complement' => '1A', 'business_name' => 'Juan SRL',
        ]);

        $this->assertSame('5115889', $c->fresh()->doc_number);
    }

    public function test_billing_identity_object(): void
    {
        $id = new BillingIdentity('1', '5115889', '1A', 'Juan SRL');
        $this->assertTrue($id->isComplete());

        $incomplete = new BillingIdentity('1', '', null, null);
        $this->assertFalse($incomplete->isComplete());
    }

    public function test_customer_billing_identity_helpers(): void
    {
        $with = Customer::create(['name' => 'A', 'doc_type' => '1', 'doc_number' => '123']);
        $without = Customer::create(['name' => 'B']);

        $this->assertTrue($with->hasBillingIdentity());
        $this->assertInstanceOf(BillingIdentity::class, $with->billingIdentity());
        $this->assertFalse($without->hasBillingIdentity());
        $this->assertNull($without->billingIdentity());
    }

    public function test_product_and_unit_hold_sin_code(): void
    {
        $unit = Unit::create(['name' => 'Pieza', 'symbol' => 'pza', 'sin_code' => '1']);
        $product = Product::factory()->create(['sin_code' => '49111']);

        $this->assertSame('1', $unit->fresh()->sin_code);
        $this->assertSame('49111', $product->fresh()->sin_code);
    }

    public function test_sale_holds_fiscal_columns(): void
    {
        $sale = Sale::factory()->create([
            'taxable_base' => 10000, 'iva_amount' => 1300, 'it_amount' => 300, 'wants_invoice' => true,
        ]);

        $fresh = $sale->fresh();
        $this->assertSame(10000, $fresh->taxable_base);
        $this->assertTrue($fresh->wants_invoice);
    }
}
