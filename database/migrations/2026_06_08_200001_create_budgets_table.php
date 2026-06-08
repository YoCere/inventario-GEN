<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('base_from');
            $table->date('base_to');
            $table->unsignedSmallInteger('years')->default(5);
            $table->decimal('growth_pct', 8, 4)->default(0);
            $table->decimal('discount_rate_pct', 8, 4)->default(12);
            $table->decimal('iue_rate_pct', 8, 4)->default(25);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
