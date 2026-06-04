<?php

namespace App\Console\Commands;

use App\Enums\JournalEntryStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RebuildLedgerSnapshot extends Command
{
    protected $signature = 'ledger:rebuild {--from=} {--to=}';

    protected $description = 'Reconstruye ledger_account_daily desde journal_entry_lines (fuente de verdad).';

    public function handle(): int
    {
        $from = $this->option('from');
        $to   = $this->option('to');

        $query = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.status', JournalEntryStatus::Posted->value)
            ->selectRaw('l.chart_of_account_id, e.entry_date as movement_date, e.entry_type,
                SUM(l.debit_amount) as debit_total, SUM(l.credit_amount) as credit_total')
            ->groupBy('l.chart_of_account_id', 'e.entry_date', 'e.entry_type');

        if ($from) {
            $query->whereDate('e.entry_date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('e.entry_date', '<=', $to);
        }

        DB::transaction(function () use ($query, $from, $to) {
            $scope = DB::table('ledger_account_daily');
            if ($from) {
                $scope->whereDate('movement_date', '>=', $from);
            }
            if ($to) {
                $scope->whereDate('movement_date', '<=', $to);
            }
            $scope->delete();

            $now = now()->toDateTimeString();

            foreach ($query->get() as $g) {
                DB::table('ledger_account_daily')->insert([
                    'chart_of_account_id' => $g->chart_of_account_id,
                    'movement_date'       => Carbon::parse($g->movement_date)->toDateString(),
                    'entry_type'          => $g->entry_type,
                    'debit_total'         => (int) $g->debit_total,
                    'credit_total'        => (int) $g->credit_total,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
            }
        });

        $this->info('Snapshot reconstruido.');
        return self::SUCCESS;
    }
}
