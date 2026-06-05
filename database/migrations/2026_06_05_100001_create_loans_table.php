<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('lender');
            $table->string('code')->unique();
            $table->unsignedBigInteger('principal');
            $table->decimal('annual_rate_pct', 8, 4)->default(0);
            $table->unsignedInteger('term_months');
            $table->date('start_date');
            $table->unsignedTinyInteger('payment_day')->default(1);
            $table->string('liability_account_code', 30)->default('2.2.01');
            $table->string('interest_account_code', 30)->default('6.3');
            $table->string('payment_account_code', 30)->default('1.1.02');
            $table->string('status', 20)->default('active');
            $table->boolean('is_opening')->default(false);
            $table->unsignedBigInteger('outstanding_balance')->default(0);
            $table->foreignId('disbursement_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
