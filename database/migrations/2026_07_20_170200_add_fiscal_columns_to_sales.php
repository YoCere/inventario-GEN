<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->bigInteger('taxable_base')->default(0)->after('total');
            $table->bigInteger('iva_amount')->default(0)->after('taxable_base');
            $table->bigInteger('it_amount')->default(0)->after('iva_amount');
            $table->boolean('wants_invoice')->default(false)->after('it_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['taxable_base', 'iva_amount', 'it_amount', 'wants_invoice']);
        });
    }
};
