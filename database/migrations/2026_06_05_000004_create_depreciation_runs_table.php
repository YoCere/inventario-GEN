<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('depreciation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->string('year_month', 7);
            $table->unsignedBigInteger('amount');
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->timestamp('posted_at');
            $table->timestamps();
            $table->unique(['fixed_asset_id', 'year_month'], 'depr_asset_month_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_runs');
    }
};
