<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite no soporta `ALTER TABLE ADD CONSTRAINT` — sus CHECK constraints
        // sólo se pueden declarar al crear la tabla. Skip explícito en dev/test
        // para no romper tests que usan SQLite :memory:.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        // Defensa en BD contra inserciones de stock/precios negativos.
        // Si el dataset existente tiene negativos, ABORTAR la migración — no aplicar
        // constraints a medias ni dejar la tabla "aparentemente protegida".
        $negativeProducts = DB::table('products')->where('quantity', '<', 0)->count();
        if ($negativeProducts > 0) {
            throw new \RuntimeException(
                "Cannot add CHECK constraints: {$negativeProducts} products with negative quantity. "
                . "Limpia los datos antes de correr esta migration."
            );
        }

        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_quantity_non_negative CHECK (quantity >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_min_stock_non_negative CHECK (min_stock >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_purchase_price_non_negative CHECK (purchase_price >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_selling_price_non_negative CHECK (selling_price >= 0)');

        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT chk_sale_items_quantity_positive CHECK (quantity > 0)');
        DB::statement('ALTER TABLE purchase_items ADD CONSTRAINT chk_purchase_items_quantity_positive CHECK (quantity > 0)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE products DROP CONSTRAINT chk_products_quantity_non_negative');
        DB::statement('ALTER TABLE products DROP CONSTRAINT chk_products_min_stock_non_negative');
        DB::statement('ALTER TABLE products DROP CONSTRAINT chk_products_purchase_price_non_negative');
        DB::statement('ALTER TABLE products DROP CONSTRAINT chk_products_selling_price_non_negative');
        DB::statement('ALTER TABLE sale_items DROP CONSTRAINT chk_sale_items_quantity_positive');
        DB::statement('ALTER TABLE purchase_items DROP CONSTRAINT chk_purchase_items_quantity_positive');
    }
};
