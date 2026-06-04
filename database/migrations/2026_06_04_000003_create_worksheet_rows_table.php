<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('worksheet_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accounting_period_id')->constrained('accounting_periods')->cascadeOnDelete();
            $table->foreignId('chart_of_account_id')->constrained('chart_of_accounts')->cascadeOnDelete();
            $table->bigInteger('saldo_inicial_debe')->default(0);
            $table->bigInteger('saldo_inicial_haber')->default(0);
            $table->bigInteger('mov_debito')->default(0);
            $table->bigInteger('mov_credito')->default(0);
            $table->bigInteger('ajuste_debito')->default(0);
            $table->bigInteger('ajuste_credito')->default(0);
            $table->bigInteger('saldo_aj_debe')->default(0);
            $table->bigInteger('saldo_aj_haber')->default(0);
            $table->bigInteger('result_debe')->default(0);
            $table->bigInteger('result_haber')->default(0);
            $table->bigInteger('balance_debe')->default(0);
            $table->bigInteger('balance_haber')->default(0);
            $table->decimal('variacion_pct', 10, 2)->nullable();
            $table->decimal('porcentaje_total', 6, 2)->nullable();
            $table->text('suggested_action')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['accounting_period_id', 'chart_of_account_id'], 'wr_period_account_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worksheet_rows');
    }
};
