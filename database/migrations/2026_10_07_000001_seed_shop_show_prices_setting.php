<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Interruptor "Mostrar precios en la tienda". Arranca encendido: las tiendas que
 * ya estaban publicadas no deben cambiar de comportamiento al desplegar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('settings')->where('key', 'shop_show_prices')->exists()) {
            DB::table('settings')->insert([
                'key' => 'shop_show_prices',
                'value' => '1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'shop_show_prices')->delete();
    }
};
