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
        Schema::create('payroll_sheet_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_sheet_id')->constrained('payroll_sheets')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');

            $table->string('employee_name');
            $table->string('position')->nullable();
            $table->enum('area', ['mod', 'moi', 'sales', 'admin'])->default('admin');
            $table->decimal('antiquity_rate', 6, 4)->default(0);
            $table->unsignedTinyInteger('worked_days')->default(30);

            $table->unsignedBigInteger('base_salary')->default(0);
            $table->unsignedBigInteger('hours_extra')->default(0);
            $table->unsignedBigInteger('other_discounts')->default(0);
            $table->boolean('apply_border_bonus')->default(true);

            $table->unsignedBigInteger('earned_base')->default(0);
            $table->unsignedBigInteger('antiquity_bonus')->default(0);
            $table->unsignedBigInteger('border_bonus')->default(0);
            $table->unsignedBigInteger('total_earned')->default(0);

            $table->unsignedBigInteger('labor_contribution')->default(0);
            $table->unsignedBigInteger('rc_iva')->default(0);
            $table->unsignedBigInteger('solidarity_1')->default(0);
            $table->unsignedBigInteger('solidarity_2')->default(0);
            $table->unsignedBigInteger('total_deductions')->default(0);
            $table->unsignedBigInteger('net_payable')->default(0);

            $table->unsignedBigInteger('employer_contribution')->default(0);
            $table->unsignedBigInteger('aguinaldo_provision')->default(0);
            $table->unsignedBigInteger('indemnization_provision')->default(0);
            $table->unsignedBigInteger('total_employer_cost')->default(0);

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['payroll_sheet_id', 'line_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_sheet_items');
    }
};

