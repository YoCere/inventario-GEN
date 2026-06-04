<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('voucher_type')->nullable()->after('entry_number');
            $table->unsignedInteger('voucher_number')->nullable()->after('voucher_type');
            $table->unique(
                ['accounting_period_id', 'voucher_type', 'voucher_number'],
                'je_period_voucher_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique('je_period_voucher_unique');
            $table->dropColumn(['voucher_type', 'voucher_number']);
        });
    }
};
