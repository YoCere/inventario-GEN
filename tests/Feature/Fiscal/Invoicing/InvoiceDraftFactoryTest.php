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

    public function test_global_discount_split_is_exact(): void
    {
        $p = Product::factory()->create();
        $sale = $this->saleWith([[$p, 1, 1000, 0], [$p, 1, 1000, 0], [$p, 1, 1000, 0]], 100); // 100/3

        $draft = app(InvoiceDraftFactory::class)->fromSale($sale);

        $this->assertSame([34, 33, 33], array_map(fn ($l) => $l->discount, $draft->lines));
    }

    public function test_free_last_line_never_goes_negative(): void
    {
        $p = Product::factory()->create();
        $sale = $this->saleWith([[$p, 1, 1000, 0], [$p, 1, 2000, 0], [$p, 1, 0, 0]], 100);

        $draft = app(InvoiceDraftFactory::class)->fromSale($sale);

        foreach ($draft->lines as $l) {
            $this->assertGreaterThanOrEqual(0, $l->unitPrice * $l->quantity - $l->discount);
        }
        $this->assertSame(0, $draft->lines[2]->discount);
        $this->assertSame($sale->total, array_sum(array_map(fn ($l) => $l->unitPrice * $l->quantity - $l->discount, $draft->lines)));
    }

    public function test_single_line_with_item_and_global_discount(): void
    {
        $p = Product::factory()->create();
        $sale = $this->saleWith([[$p, 3, 1000, 100]], 250);

        $draft = app(InvoiceDraftFactory::class)->fromSale($sale);

        $this->assertSame(550, $draft->lines[0]->discount);
        $this->assertSame($sale->total, 3000 - $draft->lines[0]->discount);
    }

    public function test_throws_when_total_does_not_match_items(): void
    {
        $p = Product::factory()->create();
        $sale = $this->saleWith([[$p, 1, 1000, 0]]);
        $sale->update(['total' => 999]);

        $this->expectException(\DomainException::class);
        app(InvoiceDraftFactory::class)->fromSale($sale->fresh());
    }

    public function test_throws_for_cancelled_sale(): void
    {
        $p = Product::factory()->create();
        $sale = $this->saleWith([[$p, 1, 1000, 0]]);
        $sale->update(['status' => \App\Enums\SaleStatus::CANCELLED]);

        $this->expectException(\DomainException::class);
        app(InvoiceDraftFactory::class)->fromSale($sale->fresh());
    }

    public function test_soft_deleted_product_still_yields_its_data(): void
    {
        $p = Product::factory()->create(['name' => 'Viejo', 'sin_code' => '7777']);
        $sale = $this->saleWith([[$p, 1, 1000, 0]]);
        $p->delete();

        $draft = app(InvoiceDraftFactory::class)->fromSale(Sale::find($sale->id));

        $this->assertSame('7777', $draft->lines[0]->sinProductCode);
        $this->assertSame('Viejo', $draft->lines[0]->description);
    }

    public function test_soft_deleted_customer_still_yields_buyer(): void
    {
        $c = Customer::factory()->create(['name' => 'Juan', 'doc_type' => '1', 'doc_number' => '1234567', 'business_name' => 'JUAN']);
        $p = Product::factory()->create();
        $sale = $this->saleWith([[$p, 1, 1000, 0]], 0, $c);
        $c->delete();

        $draft = app(InvoiceDraftFactory::class)->fromSale(Sale::find($sale->id));

        $this->assertSame('1234567', $draft->buyer->docNumber);
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
