<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class VoucherBackfiller
{
    /**
     * Backfill voucher_type and voucher_number for journal_entries rows
     * that still have voucher_number = NULL.
     *
     * Idempotent: seeds per-type counters from the existing max so partial
     * runs or re-runs never collide with already-numbered rows.
     */
    public static function run(): void
    {
        $map = [
            'App\\Models\\Sale'         => 'ingreso',
            'App\\Models\\Purchase'     => 'egreso',
            'App\\Models\\PayrollSheet' => 'traspaso',
        ];

        $periodIds = DB::table('journal_entries')
            ->distinct()
            ->pluck('accounting_period_id');

        foreach ($periodIds as $periodId) {
            // Seed counters from already-numbered rows so re-runs are safe.
            $counters = [];
            foreach (['ingreso', 'egreso', 'traspaso'] as $t) {
                $counters[$t] = (int) DB::table('journal_entries')
                    ->where('accounting_period_id', $periodId)
                    ->where('voucher_type', $t)
                    ->max('voucher_number');
            }

            $entries = DB::table('journal_entries')
                ->where('accounting_period_id', $periodId)
                ->whereNull('voucher_number')
                ->orderBy('entry_date')
                ->orderBy('id')
                ->get(['id', 'source_type']);

            foreach ($entries as $e) {
                $type = $map[$e->source_type] ?? 'traspaso';
                $counters[$type]++;
                DB::table('journal_entries')
                    ->where('id', $e->id)
                    ->update([
                        'voucher_type'   => $type,
                        'voucher_number' => $counters[$type],
                    ]);
            }
        }
    }
}
