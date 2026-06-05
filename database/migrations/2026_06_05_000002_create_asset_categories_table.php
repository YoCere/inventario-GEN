<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('useful_life_months');
            $table->decimal('annual_rate_pct', 6, 2)->default(0);
            $table->boolean('is_deferred')->default(false);
            $table->string('ppe_account_code', 30);
            $table->string('accumulated_account_code', 30);
            $table->string('expense_account_code', 30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_categories');
    }
};
