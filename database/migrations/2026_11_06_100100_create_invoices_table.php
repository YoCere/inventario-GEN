<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->unique()->constrained()->restrictOnDelete();
            $t->string('provider', 20);                       // falmus | simulator
            $t->string('status', 20)->index();
            $t->string('provider_status', 40)->nullable();    // texto crudo del proveedor
            $t->unsignedSmallInteger('document_sector')->default(1);
            $t->string('point_of_sale_ref')->nullable();      // puntoVentaId (UUID) usado
            // Respuesta del proveedor
            $t->uuid('external_id')->nullable()->unique();    // facturaId de Falmus
            $t->string('cuf')->nullable()->index();
            $t->unsignedBigInteger('number')->nullable();     // nroFactura fiscal
            $t->unsignedSmallInteger('siat_status_code')->nullable();
            $t->string('url_pdf')->nullable();
            $t->string('url_xml')->nullable();
            $t->text('url_qr')->nullable();
            $t->timestamp('issued_at')->nullable();
            // Foto del comprador al momento de facturar (el cliente puede cambiar después)
            $t->string('buyer_doc_type', 4)->nullable();
            $t->string('buyer_doc_number', 30)->nullable();
            $t->string('buyer_doc_complement', 10)->nullable();
            $t->string('buyer_name')->nullable();
            $t->string('buyer_code', 40)->nullable();
            $t->bigInteger('total');                          // centavos
            // Anulación
            $t->unsignedSmallInteger('void_reason_code')->nullable();
            $t->timestamp('voided_at')->nullable();
            // Diagnóstico
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->json('last_error')->nullable();               // problem+json, incluye traceId
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
