<?php

namespace Tests\Feature\Fiscal\Invoicing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Sale;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_has_at_most_one_invoice(): void
    {
        $sale = Sale::factory()->create();
        $inv = Invoice::create(['sale_id' => $sale->id, 'provider' => 'simulator', 'status' => InvoiceStatus::DRAFT, 'total' => 1000]);

        $this->assertTrue($sale->invoice->is($inv));

        $this->expectException(QueryException::class);
        Invoice::create(['sale_id' => $sale->id, 'provider' => 'simulator', 'status' => InvoiceStatus::DRAFT, 'total' => 1000]);
    }

    public function test_maps_falmus_states(): void
    {
        $this->assertSame(InvoiceStatus::ACCEPTED, InvoiceStatus::fromProvider('aceptado'));
        $this->assertSame(InvoiceStatus::PENDING_SEND, InvoiceStatus::fromProvider('pendiente_envio'));
        $this->assertSame(InvoiceStatus::VOIDED, InvoiceStatus::fromProvider('anulado'));
        $this->assertSame(InvoiceStatus::NEEDS_REVIEW, InvoiceStatus::fromProvider('requiere_revision'));
        $this->assertSame(InvoiceStatus::NEEDS_REVIEW, InvoiceStatus::fromProvider('algo_nuevo'));
    }

    public function test_only_final_states_block_reissue(): void
    {
        $this->assertTrue(InvoiceStatus::ACCEPTED->blocksReissue());
        $this->assertTrue(InvoiceStatus::PENDING_SEND->blocksReissue());
        $this->assertTrue(InvoiceStatus::UNCERTAIN->blocksReissue());
        $this->assertFalse(InvoiceStatus::FAILED->blocksReissue());
        $this->assertFalse(InvoiceStatus::DRAFT->blocksReissue());
    }
}
