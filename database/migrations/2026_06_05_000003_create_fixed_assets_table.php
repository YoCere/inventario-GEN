<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_category_id')->constrained('asset_categories')->restrictOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->date('acquisition_date');
            $table->unsignedBigInteger('acquisition_cost');
            $table->unsignedBigInteger('residual_value')->default(0);
            $table->unsignedInteger('useful_life_months');
            $table->date('depreciation_start_date');
            $table->string('status', 30)->default('active');
            $table->unsignedBigInteger('accumulated_depreciation')->default(0);
            $table->boolean('is_opening')->default(false);
            $table->foreignId('acquisition_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->date('disposal_date')->nullable();
            $table->unsignedBigInteger('disposal_amount')->nullable();
            $table->foreignId('disposal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};
