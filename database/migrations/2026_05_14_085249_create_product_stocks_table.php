<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->integer('quantity')->default(0);
            $table->integer('min_stock')->nullable(); // override product.min_stock per location
            $table->timestamps();

            $table->unique(['product_id', 'location_id']);
            $table->index('product_id');
            $table->index('location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stocks');
    }
};
