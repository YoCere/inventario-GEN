<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seedea (insert-if-missing) el setting facturacion_activada, apagado por
     * defecto. Gatilla más adelante el toggle de facturación en el POS.
     */
    public function up(): void
    {
        if (! DB::table('settings')->where('key', 'facturacion_activada')->exists()) {
            DB::table('settings')->insert([
                'key' => 'facturacion_activada',
                'value' => '0',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')->where('key', 'facturacion_activada')->delete();
    }
};
