<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bills_of_material', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('mod_rate')->default(0);
            $table->unsignedBigInteger('moi_rate')->default(0);
            $table->unsignedBigInteger('cif_rate')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills_of_material');
    }
};
