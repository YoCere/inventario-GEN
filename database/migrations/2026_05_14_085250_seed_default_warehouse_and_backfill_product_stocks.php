<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // 1. Default warehouse (idempotent)
        $warehouseId = DB::table('warehouses')->where('is_default', true)->value('id');
        if (!$warehouseId) {
            $warehouseId = DB::table('warehouses')->insertGetId([
                'name' => 'Almacén Principal',
                'address' => null,
                'is_active' => true,
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 2. Default location inside default warehouse (idempotent)
        $locationId = DB::table('locations')
            ->where('warehouse_id', $warehouseId)
            ->where('is_default', true)
            ->value('id');
        if (!$locationId) {
            $locationId = DB::table('locations')->insertGetId([
                'warehouse_id' => $warehouseId,
                'parent_location_id' => null,
                'name' => 'General',
                'type' => 'section',
                'is_active' => true,
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 3. Backfill product_stocks from products.quantity (idempotent)
        $products = DB::table('products')->select('id', 'quantity', 'min_stock')->get();
        foreach ($products as $product) {
            $exists = DB::table('product_stocks')
                ->where('product_id', $product->id)
                ->where('location_id', $locationId)
                ->exists();

            if (!$exists) {
                DB::table('product_stocks')->insert([
                    'product_id' => $product->id,
                    'location_id' => $locationId,
                    'quantity' => $product->quantity,
                    'min_stock' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Reversible: drop seeded data
        $warehouseId = DB::table('warehouses')->where('is_default', true)->value('id');
        if ($warehouseId) {
            DB::table('product_stocks')
                ->whereIn('location_id', function ($q) use ($warehouseId) {
                    $q->select('id')->from('locations')->where('warehouse_id', $warehouseId);
                })
                ->delete();
            DB::table('locations')->where('warehouse_id', $warehouseId)->delete();
            DB::table('warehouses')->where('id', $warehouseId)->delete();
        }
    }
};
