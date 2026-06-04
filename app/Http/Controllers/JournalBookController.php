<?php

namespace App\Http\Controllers;

use App\Enums\AccountingPeriodStatus;
use App\Enums\JournalEntryStatus;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\Setting;
use Illuminate\Http\Request;

class JournalBookController extends Controller
{
    public function index(Request $request)
    {
        $data = $this->buildReport($request);

        return view('finance-journal-book.index', $data);
    }

    public function print(Request $request)
    {
        $data = $this->buildReport($request);

        return view('finance-journal-book.print', $data);
    }

    private function buildReport(Request $request): array
    {
        // ── 1. Resolve date range ────────────────────────────────────────────
        $from = $request->input('from');
        $to   = $request->input('to');

        if (! $from || ! $to) {
            $period = AccountingPeriod::query()
                ->where('status', AccountingPeriodStatus::Open->value)
                ->whereDate('start_date', '<=', today())
                ->whereDate('end_date', '>=', today())
                ->first();

            if ($period) {
                $from = $from ?: $period->start_date->format('Y-m-d');
                $to   = $to   ?: $period->end_date->format('Y-m-d');
            } else {
                $from = $from ?: now()->startOfMonth()->format('Y-m-d');
                $to   = $to   ?: now()->endOfMonth()->format('Y-m-d');
            }
        }

        // ── 2. Query posted entries with their lines and account ─────────────
        $entries = JournalEntry::with(['lines.account'])
            ->whereBetween('entry_date', [$from, $to])
            ->where('status', JournalEntryStatus::Posted)
            ->orderBy('entry_date')
            ->orderBy('voucher_type')
            ->orderBy('voucher_number')
            ->get();

        // ── 3. Build structured rows (keep blade dumb) ───────────────────────
        $rows         = [];
        $totalDebit   = 0;
        $totalCredit  = 0;

        foreach ($entries as $entry) {
            // Sort lines: debit lines first, then credit lines
            $sorted = $entry->lines->sortByDesc(fn ($l) => $l->debit_amount > 0 ? 1 : 0);

            $lines          = [];
            $subtotalDebit  = 0;
            $subtotalCredit = 0;

            foreach ($sorted as $line) {
                $lines[] = [
                    'code'   => $line->account?->code   ?? '—',
                    'name'   => $line->account?->name   ?? '—',
                    'debit'  => $line->debit_amount,
                    'credit' => $line->credit_amount,
                ];
                $subtotalDebit  += $line->debit_amount;
                $subtotalCredit += $line->credit_amount;
            }

            $totalDebit  += $subtotalDebit;
            $totalCredit += $subtotalCredit;

            $rows[] = [
                'date'            => $entry->entry_date->format('d/m/Y'),
                'voucher_label'   => $entry->voucher_type?->shortLabel() ?? '—',
                'voucher_number'  => $entry->voucher_number,
                'glosa'           => $entry->description,
                'lines'           => $lines,
                'subtotal_debit'  => $subtotalDebit,
                'subtotal_credit' => $subtotalCredit,
            ];
        }

        // ── 4. Company header ────────────────────────────────────────────────
        $storeName    = Setting::get('store_name',    config('app.name'));
        $storeAddress = Setting::get('store_address', '');
        $storePhone   = Setting::get('store_phone',   '');

        return compact(
            'rows',
            'from',
            'to',
            'totalDebit',
            'totalCredit',
            'storeName',
            'storeAddress',
            'storePhone',
        );
    }
}
