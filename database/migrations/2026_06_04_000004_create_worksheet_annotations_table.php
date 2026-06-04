<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('worksheet_annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accounting_period_id')->constrained('accounting_periods')->cascadeOnDelete();
            $table->foreignId('chart_of_account_id')->constrained('chart_of_accounts')->cascadeOnDelete();
            $table->text('manual_note')->nullable();
            $table->string('action_status', 20)->default('pendiente'); // pendiente | hecho | descartado
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['accounting_period_id', 'chart_of_account_id'], 'wa_period_account_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worksheet_annotations');
    }
};
