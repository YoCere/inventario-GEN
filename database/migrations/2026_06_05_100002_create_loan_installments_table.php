<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('loan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->date('due_date');
            $table->unsignedBigInteger('payment_amount');
            $table->unsignedBigInteger('interest_amount');
            $table->unsignedBigInteger('principal_amount');
            $table->unsignedBigInteger('balance_after');
            $table->string('status', 20)->default('pending');
            $table->date('paid_date')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->unique(['loan_id', 'number'], 'loan_installment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_installments');
    }
};
