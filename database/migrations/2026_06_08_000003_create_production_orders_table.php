<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('bom_id')->constrained('bills_of_material')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->date('production_date');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->unsignedBigInteger('material_cost')->default(0);
            $table->unsignedBigInteger('mod_cost')->default(0);
            $table->unsignedBigInteger('moi_cost')->default(0);
            $table->unsignedBigInteger('cif_cost')->default(0);
            $table->unsignedBigInteger('total_cost')->default(0);
            $table->unsignedBigInteger('unit_cost')->default(0);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('status', 20)->default('completed');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_orders');
    }
};
