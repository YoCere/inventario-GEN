<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('slug', 180)->nullable()->after('name');
            $table->boolean('is_public')->default(false)->index()->after('is_active');
            $table->boolean('featured')->default(false)->index()->after('is_public');
            $table->integer('sort_order')->default(0)->index()->after('featured');
        });

        // Backfill slugs for existing products. Append -{id} to guarantee uniqueness
        // even when two products share the same name.
        Product::query()->orderBy('id')->chunkById(200, function ($products) {
            foreach ($products as $product) {
                $base = Str::slug($product->name) ?: 'producto';
                $product->slug = $base . '-' . $product->id;
                $product->saveQuietly();
            }
        });

        // Add unique index after backfill to avoid collision errors during backfill.
        Schema::table('products', function (Blueprint $table) {
            $table->string('slug', 180)->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'is_public', 'featured', 'sort_order']);
        });
    }
};
