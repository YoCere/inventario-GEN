<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Código de actividad económica SIN (catálogo "actividades"). Nullable: si falta,
    // se usa el setting fiscal_default_activity (la mayoría de negocios tiene una sola).
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->string('economic_activity', 20)->nullable()->after('sin_code'));
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('economic_activity'));
    }
};
