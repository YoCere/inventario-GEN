<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        // Defensa en BD contra inserciones de stock negativo.
        // Si dataset existente tiene negativos, log y skip (no romper migrations).
        try {
            $negativeProducts = DB::table('products')->where('quantity', '<', 0)->count();

            if ($negativeProducts > 0) {
                Log::warning('CHECK constraint skipped: products with negative quantity exist', [
                    'count' => $negativeProducts,
                ]);
                return;
            }

            DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_quantity_non_negative CHECK (quantity >= 0)');
            DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_min_stock_non_negative CHECK (min_stock >= 0)');
            DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_purchase_price_non_negative CHECK (purchase_price >= 0)');
            DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_selling_price_non_negative CHECK (selling_price >= 0)');

            DB::statement('ALTER TABLE sale_items ADD CONSTRAINT chk_sale_items_quantity_positive CHECK (quantity > 0)');
            DB::statement('ALTER TABLE purchase_items ADD CONSTRAINT chk_purchase_items_quantity_positive CHECK (quantity > 0)');
        } catch (\Throwable $e) {
            Log::error('Failed adding CHECK constraints', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        try {
            DB::statement('ALTER TABLE products DROP CONSTRAINT chk_products_quantity_non_negative');
            DB::statement('ALTER TABLE products DROP CONSTRAINT chk_products_min_stock_non_negative');
            DB::statement('ALTER TABLE products DROP CONSTRAINT chk_products_purchase_price_non_negative');
            DB::statement('ALTER TABLE products DROP CONSTRAINT chk_products_selling_price_non_negative');
            DB::statement('ALTER TABLE sale_items DROP CONSTRAINT chk_sale_items_quantity_positive');
            DB::statement('ALTER TABLE purchase_items DROP CONSTRAINT chk_purchase_items_quantity_positive');
        } catch (\Throwable $e) {
            Log::error('Failed dropping CHECK constraints', ['error' => $e->getMessage()]);
        }
    }
};
