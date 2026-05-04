<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Rename 'Product Sales' to 'Ventas de Productos'
        DB::table('finance_categories')
            ->where('name', 'Product Sales')
            ->update(['name' => 'Ventas de Productos']);

        // Rename 'Product Purchases' to 'Compras de Productos'
        DB::table('finance_categories')
            ->where('name', 'Product Purchases')
            ->update(['name' => 'Compras de Productos']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rename back 'Ventas de Productos' to 'Product Sales'
        DB::table('finance_categories')
            ->where('name', 'Ventas de Productos')
            ->update(['name' => 'Product Sales']);

        // Rename back 'Compras de Productos' to 'Product Purchases'
        DB::table('finance_categories')
            ->where('name', 'Compras de Productos')
            ->update(['name' => 'Product Purchases']);
    }
};
