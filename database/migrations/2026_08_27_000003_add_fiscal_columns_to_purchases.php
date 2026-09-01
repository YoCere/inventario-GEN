<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('taxable_base')->default(0);
            $table->unsignedBigInteger('iva_amount')->default(0);
            $table->boolean('wants_invoice')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['taxable_base', 'iva_amount', 'wants_invoice']);
        });
    }
};
