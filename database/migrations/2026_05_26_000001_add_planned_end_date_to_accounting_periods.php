<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega planned_end_date a accounting_periods.
 *
 * Contexto: cuando un periodo se cierra antes de su fecha fin planificada,
 * end_date se trunca al día real de cierre y aquí se guarda la fecha
 * originalmente prevista — solo para referencia/auditoría. Esto permite
 * crear nuevos periodos para las fechas que el periodo original ya no
 * cubre sin bloquear el overlap check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_periods', function (Blueprint $table) {
            $table->date('planned_end_date')
                  ->nullable()
                  ->after('end_date')
                  ->comment('Fecha fin original cuando el periodo se cerró anticipadamente.');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_periods', function (Blueprint $table) {
            $table->dropColumn('planned_end_date');
        });
    }
};
