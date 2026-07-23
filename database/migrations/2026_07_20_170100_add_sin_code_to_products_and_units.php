<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->string('sin_code')->nullable()->after('sku'));
        Schema::table('units', fn (Blueprint $t) => $t->string('sin_code')->nullable()->after('symbol'));
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('sin_code'));
        Schema::table('units', fn (Blueprint $t) => $t->dropColumn('sin_code'));
    }
};
