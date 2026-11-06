<?php

namespace Tests\Feature\Fiscal\Invoicing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_keeps_history_and_invoice_is_latest(): void
    {
        $sale = Sale::factory()->create();
        Invoice::create(['sale_id' => $sale->id, 'provider' => 'simulator', 'status' => InvoiceStatus::VOIDED, 'total' => 1000]);
        $second = Invoice::create(['sale_id' => $sale->id, 'provider' => 'simulator', 'status' => InvoiceStatus::ACCEPTED, 'total' => 1000]);

        $this->assertCount(2, $sale->invoices);
        $this->assertTrue($sale->fresh()->invoice->is($second));
    }

    public function test_invoice_sale_resolves_when_sale_soft_deleted(): void
    {
        $sale = Sale::factory()->create();
        $inv = Invoice::create(['sale_id' => $sale->id, 'provider' => 'simulator', 'status' => InvoiceStatus::DRAFT, 'total' => 1000]);
        $sale->delete();

        $this->assertTrue($inv->fresh()->sale->is($sale));
    }

    public function test_maps_falmus_states(): void
    {
        $map = [
            'aceptado' => InvoiceStatus::ACCEPTED,
            'observado' => InvoiceStatus::OBSERVED,
            'rechazado' => InvoiceStatus::REJECTED,
            'anulado' => InvoiceStatus::VOIDED,
            'pendiente_envio' => InvoiceStatus::PENDING_SEND,
            'pendiente_validacion' => InvoiceStatus::PENDING_VALIDATION,
            'evento_incierto' => InvoiceStatus::UNCERTAIN,
            'requiere_revision' => InvoiceStatus::NEEDS_REVIEW,
            'algo_nuevo' => InvoiceStatus::NEEDS_REVIEW,
            ' Aceptado ' => InvoiceStatus::ACCEPTED,
        ];
        foreach ($map as $raw => $expected) {
            $this->assertSame($expected, InvoiceStatus::fromProvider($raw), "'$raw'");
        }
    }

    public function test_blocks_reissue_decision_for_every_status(): void
    {
        $table = [
            'draft' => false, 'issuing' => true, 'accepted' => true, 'observed' => true,
            'rejected' => false, 'voided' => true, 'pending_send' => true,
            'pending_validation' => true, 'uncertain' => true, 'needs_review' => true,
            'failed' => false,
        ];
        $this->assertCount(count(InvoiceStatus::cases()), $table, 'Nuevo estado: decidir si bloquea reemisión');
        foreach (InvoiceStatus::cases() as $case) {
            $this->assertSame($table[$case->value], $case->blocksReissue(), $case->value);
        }
    }
}
