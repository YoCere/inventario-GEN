<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bom_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_id')->constrained('bills_of_material')->cascadeOnDelete();
            $table->foreignId('component_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity_per_unit', 12, 4);
            $table->timestamps();
            $table->unique(['bom_id', 'component_product_id'], 'bom_component_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_components');
    }
};
