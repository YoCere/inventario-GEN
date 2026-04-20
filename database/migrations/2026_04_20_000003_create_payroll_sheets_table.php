<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payroll_sheets', function (Blueprint $table) {
            $table->id();
            $table->string('sheet_number')->unique();
            $table->date('period_month');
            $table->date('payment_date');
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'posted'])->default('draft');

            $table->unsignedBigInteger('total_earned')->default(0);
            $table->unsignedBigInteger('total_deductions')->default(0);
            $table->unsignedBigInteger('net_payable')->default(0);
            $table->unsignedBigInteger('total_employer_contributions')->default(0);
            $table->unsignedBigInteger('total_employer_cost')->default(0);

            $table->foreignId('accounting_period_id')->nullable()->constrained('accounting_periods')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->index(['period_month', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_sheets');
    }
};

