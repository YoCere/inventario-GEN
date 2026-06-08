<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('budgets')->cascadeOnDelete();
            $table->string('chart_of_account_code', 30);
            $table->string('name');
            $table->string('line_type', 20);
            $table->bigInteger('base_amount')->default(0);
            $table->decimal('growth_pct', 8, 4)->nullable();
            $table->timestamps();
            $table->unique(['budget_id', 'chart_of_account_code'], 'budget_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
    }
};
