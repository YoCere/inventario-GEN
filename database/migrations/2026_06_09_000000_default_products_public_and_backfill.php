<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Cambia la visibilidad por defecto del catálogo público:
     *   1. Default de la columna is_public pasa de false a true (todo producto
     *      nuevo entra al catálogo salvo que el admin lo desmarque).
     *   2. Backfill: publica los productos activos existentes (is_active=true)
     *      que estaban en is_public=false. Los inactivos NO se tocan.
     *
     * Nota: usar DB::table (no Eloquent) para no arrastrar el global scope de
     * SoftDeletes; filtramos deleted_at manualmente.
     */
    public function up(): void
    {
        // 1. Default de columna → true (MySQL).
        DB::statement('ALTER TABLE products ALTER COLUMN is_public SET DEFAULT 1');

        // 2. Backfill solo activos no eliminados.
        DB::table('products')
            ->where('is_active', true)
            ->where('is_public', false)
            ->whereNull('deleted_at')
            ->update(['is_public' => true]);

        // Invalidar caches del Shop para que el catálogo refleje al instante.
        Cache::forget('shop.categories_with_public_products');
        Cache::forget('shop.price_range');
    }

    public function down(): void
    {
        // Revertir solo el default. El backfill no es reversible (no se guardó
        // qué productos estaban en false antes).
        DB::statement('ALTER TABLE products ALTER COLUMN is_public SET DEFAULT 0');
    }
};
