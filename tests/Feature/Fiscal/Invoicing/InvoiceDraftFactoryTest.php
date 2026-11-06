<?php

namespace Tests\Feature\Fiscal\Invoicing;

use App\Enums\PaymentMethod;
use App\Fiscal\Invoicing\InvoiceDraftFactory;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceDraftFactoryTest extends TestCase
{
    use RefreshDatabase;

    private function saleWith(array $lines, int $globalDiscount = 0, ?Customer $customer = null): Sale
    {
        $subtotal = 0;
        foreach ($lines as [$p, $qty, $unit, $disc]) {
            $subtotal += ($unit - $disc) * $qty;
        }
        $sale = Sale::factory()->create([
            'customer_id' => $customer?->id,
            'subtotal' => $subtotal,
            'global_discount' => $globalDiscount,
            'total' => $subtotal - $globalDiscount,
            'payment_method' => PaymentMethod::CARD,
        ]);
        foreach ($lines as [$p, $qty, $unit, $disc]) {
            SaleItem::create([
                'sale_id' => $sale->id, 'product_id' => $p->id, 'quantity' => $qty,
                'cost_price' => 0, 'unit_price' => $unit, 'discount' => $disc,
                'final_price' => $unit - $disc, 'subtotal' => ($unit - $disc) * $qty,
            ]);
        }

        return $sale->fresh('items.product.unit', 'customer');
    }

    public function test_lines_carry_sin_codes_and_amounts_add_up_with_global_discount(): void
    {
        Setting::set('fiscal_default_activity', '477300');
        $unit = Unit::factory()->create(['sin_code' => '58']);
        $a = Product::factory()->create(['name' => 'Arroz', 'sin_code' => '1001', 'unit_id' => $unit->id]);
        $b = Product::factory()->create(['name' => 'Azúcar', 'sin_code' => '1002', 'unit_id' => $unit->id, 'economic_activity' => '461000']);

        // 3 × 10.00 con 1.00 desc/u  +  1 × 5.00 ; global 1.00 → total 31.00
        $sale = $this->saleWith([[$a, 3, 1000, 100], [$b, 1, 500, 0]], 100);

        $draft = app(InvoiceDraftFactory::class)->fromSale($sale);

        $this->assertSame(1, $draft->documentSector);
        $this->assertSame(2, $draft->paymentMethodCode); // CARD
        $this->assertCount(2, $draft->lines);
        $this->assertSame('477300', $draft->lines[0]->economicActivity); // fallback setting
        $this->assertSame('461000', $draft->lines[1]->economicActivity);
        $this->assertSame('1001', $draft->lines[0]->sinProductCode);
        $this->assertSame('58', $draft->lines[0]->sinUnitCode);
        $this->assertSame(1000, $draft->lines[0]->unitPrice);

        $sum = array_sum(array_map(fn ($l) => $l->unitPrice * $l->quantity - $l->discount, $draft->lines));
        $this->assertSame($sale->total, $sum);
        $this->assertSame($sale->total, $draft->total);
    }

    public function test_global_discount_remainder_goes_to_last_line(): void
    {
        $p = Product::factory()->create();
        $sale = $this->saleWith([[$p, 1, 1000, 0], [$p, 1, 1000, 0], [$p, 1, 1000, 0]], 100); // 100/3

        $draft = app(InvoiceDraftFactory::class)->fromSale($sale);

        $this->assertSame([33, 33, 34], array_map(fn ($l) => $l->discount, $draft->lines));
    }

    public function test_buyer_snapshot_from_customer_identity(): void
    {
        $c = Customer::factory()->create([
            'name' => 'Juan', 'doc_type' => '1', 'doc_number' => '1234567',
            'doc_complement' => '1A', 'business_name' => 'JUAN PEREZ',
        ]);
        $p = Product::factory()->create();
        $draft = app(InvoiceDraftFactory::class)->fromSale($this->saleWith([[$p, 1, 1000, 0]], 0, $c));

        $this->assertSame('1', $draft->buyer->docType);
        $this->assertSame('1234567', $draft->buyer->docNumber);
        $this->assertSame('1A', $draft->buyer->complement);
        $this->assertSame('JUAN PEREZ', $draft->buyer->name);
        $this->assertSame('C-'.$c->id, $draft->buyer->code);
    }

    public function test_sale_without_identity_has_null_buyer(): void
    {
        $p = Product::factory()->create();
        $draft = app(InvoiceDraftFactory::class)->fromSale($this->saleWith([[$p, 1, 1000, 0]]));

        $this->assertNull($draft->buyer);
    }
}
