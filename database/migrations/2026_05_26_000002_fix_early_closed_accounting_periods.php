<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige períodos contables que fueron cerrados anticipadamente pero
 * cuyo end_date no fue truncado (comportamiento anterior al fix).
 *
 * Para cada periodo cerrado donde closed_at < end_date:
 *  - Mueve end_date original a planned_end_date (auditoría)
 *  - Trunca end_date al día real de cierre (closed_at::date)
 *
 * Esto libera las fechas posteriores al cierre real para que puedan
 * pertenecer a nuevos períodos sin conflicto de overlap.
 */
return new class extends Migration
{
    public function up(): void
    {
        $periods = DB::table('accounting_periods')
            ->where('status', 'closed')
            ->whereNotNull('closed_at')
            ->whereNull('planned_end_date')
            ->get();

        foreach ($periods as $period) {
            $closedDate = \Carbon\Carbon::parse($period->closed_at)->toDateString();
            $endDate    = $period->end_date;

            // Solo corregir si fue cerrado ANTES de su fecha fin planificada
            if ($closedDate < $endDate) {
                DB::table('accounting_periods')
                    ->where('id', $period->id)
                    ->update([
                        'planned_end_date' => $endDate,
                        'end_date'         => $closedDate,
                        'updated_at'       => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        // Revertir: restaurar end_date desde planned_end_date y limpiar
        $periods = DB::table('accounting_periods')
            ->whereNotNull('planned_end_date')
            ->get();

        foreach ($periods as $period) {
            DB::table('accounting_periods')
                ->where('id', $period->id)
                ->update([
                    'end_date'         => $period->planned_end_date,
                    'planned_end_date' => null,
                    'updated_at'       => now(),
                ]);
        }
    }
};
