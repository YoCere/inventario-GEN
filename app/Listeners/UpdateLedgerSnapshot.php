<?php

namespace App\Listeners;

use App\Events\JournalEntryPosted;
use App\Models\LedgerAccountDaily;
use Illuminate\Support\Facades\DB;

/**
 * Mantiene el read model incremental `ledger_account_daily` (cuenta × día ×
 * entry_type) sumando cada línea del asiento posteado. Escribe con el query
 * builder a propósito (ver comentario en handle) para no romper el lookup por
 * el cast 'date'. Registrado por auto-discovery de Laravel 11; NO agregar un
 * Event::listen manual (duplicaría el conteo).
 */
class UpdateLedgerSnapshot
{
    public function handle(JournalEntryPosted $event): void
    {
        $entry = $event->entry;
        $entry->loadMissing('lines');

        $date = $entry->entry_date instanceof \Carbon\Carbon
            ? $entry->entry_date->toDateString()
            : (string) $entry->entry_date;
        $type = $entry->entry_type instanceof \App\Enums\JournalEntryType
            ? $entry->entry_type->value
            : (string) $entry->entry_type;

        $now = now()->toDateTimeString();

        foreach ($entry->lines as $line) {
            $debit  = (int) $line->debit_amount;
            $credit = (int) $line->credit_amount;

            // Use the query builder directly so the plain date string is
            // stored and matched as-is (avoids the Eloquent 'date' cast
            // converting '2026-01-15' → Carbon → '2026-01-15 00:00:00'
            // which breaks the unique-key lookup on SQLite).
            $affected = DB::table('ledger_account_daily')
                ->where('chart_of_account_id', $line->chart_of_account_id)
                ->where('movement_date', $date)
                ->where('entry_type', $type)
                ->update([
                    'debit_total'  => DB::raw('debit_total  + ' . $debit),
                    'credit_total' => DB::raw('credit_total + ' . $credit),
                    'updated_at'   => $now,
                ]);

            if ($affected === 0) {
                DB::table('ledger_account_daily')->insert([
                    'chart_of_account_id' => $line->chart_of_account_id,
                    'movement_date'       => $date,
                    'entry_type'          => $type,
                    'debit_total'         => $debit,
                    'credit_total'        => $credit,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
            }
        }
    }
}
