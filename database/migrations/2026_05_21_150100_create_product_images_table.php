<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');                     // original / full size canonical path
            $table->string('path_thumb')->nullable();   // ~200px webp
            $table->string('path_card')->nullable();    // ~600px webp
            $table->string('path_full')->nullable();    // ~1200px webp
            $table->string('alt_text', 200)->nullable();
            $table->integer('sort_order')->default(0)->index();
            $table->boolean('is_primary')->default(false)->index();
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });

        // Backfill: migrar products.image_path existentes a una fila product_images
        // marcada como primary. Mantenemos products.image_path por compatibilidad
        // hasta deprecación formal en una fase posterior.
        $existing = DB::table('products')
            ->whereNotNull('image_path')
            ->where('image_path', '!=', '')
            ->select('id', 'image_path')
            ->get();

        $now = now();
        $rows = [];
        foreach ($existing as $product) {
            $rows[] = [
                'product_id' => $product->id,
                'path' => $product->image_path,
                'path_thumb' => null,
                'path_card' => null,
                'path_full' => null,
                'alt_text' => null,
                'sort_order' => 0,
                'is_primary' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($rows)) {
            // Chunk insert para datasets grandes.
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('product_images')->insert($chunk);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
