<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ledger_account_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chart_of_account_id')->constrained('chart_of_accounts')->cascadeOnDelete();
            $table->date('movement_date');
            $table->string('entry_type', 20); // normal | ajuste
            $table->unsignedBigInteger('debit_total')->default(0);  // centavos
            $table->unsignedBigInteger('credit_total')->default(0); // centavos
            $table->timestamps();

            $table->unique(['chart_of_account_id', 'movement_date', 'entry_type'], 'lad_account_date_type_unique');
            $table->index('movement_date');
            $table->index(['chart_of_account_id', 'movement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_account_daily');
    }
};
