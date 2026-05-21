<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Datos comprador para reservas web sin Customer asociado.
            $table->string('buyer_name', 150)->nullable()->after('customer_id');
            $table->string('buyer_phone', 30)->nullable()->index()->after('buyer_name');

            // Canal de origen. Permite distinguir reservas web del POS en reportes
            // y aplicar reglas diferentes (skip validaciones POS-específicas).
            $table->string('source', 16)->default('pos')->index()->after('payment_method');
        });

        // Backfill explícito por si el DEFAULT no aplica a filas existentes
        // (algunos engines lo hacen, otros no). Garantiza source='pos' para POS legacy.
        DB::table('sales')->whereNull('source')->update(['source' => 'pos']);
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['buyer_phone']);
            $table->dropIndex(['source']);
            $table->dropColumn(['buyer_name', 'buyer_phone', 'source']);
        });
    }
};
