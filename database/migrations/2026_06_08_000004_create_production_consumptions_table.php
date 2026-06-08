<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('production_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->foreignId('component_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 12, 4);
            $table->unsignedBigInteger('unit_cost')->default(0);
            $table->unsignedBigInteger('total_cost')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_consumptions');
    }
};
