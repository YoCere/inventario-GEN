<?php

namespace App\Services\Accounting;

use App\Enums\FixedAssetStatus;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\DepreciationRun;
use App\Models\FixedAsset;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DepreciationService
{
    public function __construct(private JournalEntryService $journal)
    {
    }

    public function monthlyAmount(FixedAsset $asset): int
    {
        $base = $asset->depreciableBase();
        $life = max(1, (int) $asset->useful_life_months);
        $remaining = $base - (int) $asset->accumulated_depreciation;
        if ($remaining <= 0) {
            return 0;
        }
        $standard = (int) round($base / $life);
        return min($standard, $remaining);
    }

    /**
     * @return array{processed:int, total:int, skipped:int}
     */
    public function runForMonth(string $yearMonth): array
    {
        $monthEnd = Carbon::createFromFormat('Y-m-d', $yearMonth . '-01')->endOfMonth();
        $systemUserId = (int) (Setting::get('system_user_id') ?: (User::query()->min('id') ?? 1));
        $processed = 0; $total = 0; $skipped = 0;

        $assets = FixedAsset::where('status', FixedAssetStatus::Active->value)
            ->whereDate('depreciation_start_date', '<=', $monthEnd->toDateString())
            ->get();

        foreach ($assets as $asset) {
            if (DepreciationRun::where('fixed_asset_id', $asset->id)->where('year_month', $yearMonth)->exists()) {
                $skipped++;
                continue;
            }

            $amount = $this->monthlyAmount($asset);
            if ($amount <= 0) {
                $asset->update(['status' => FixedAssetStatus::FullyDepreciated->value]);
                continue;
            }

            $cat = $asset->category;
            $expenseAcc = ChartOfAccount::where('code', $cat->expense_account_code)->firstOrFail();
            $accumAcc = ChartOfAccount::where('code', $cat->accumulated_account_code)->firstOrFail();
            $period = AccountingPeriod::resolveOpenForDate($monthEnd->toDateString());
            $label = $cat->is_deferred ? 'Amortización' : 'Depreciación';

            DB::transaction(function () use ($asset, $amount, $expenseAcc, $accumAcc, $period, $monthEnd, $yearMonth, $label, $systemUserId) {
                $entry = $this->journal->createPostedEntry([
                    'entry_date' => $monthEnd->toDateString(),
                    'accounting_period_id' => $period->id,
                    'description' => "{$label} {$yearMonth} - {$asset->code} {$asset->name}",
                    'entry_type' => 'ajuste',
                    'voucher_type' => 'traspaso',
                    'created_by' => $systemUserId,
                ], [
                    ['chart_of_account_id' => $expenseAcc->id, 'debit_amount' => $amount, 'credit_amount' => 0],
                    ['chart_of_account_id' => $accumAcc->id, 'debit_amount' => 0, 'credit_amount' => $amount],
                ]);

                DepreciationRun::create([
                    'fixed_asset_id' => $asset->id,
                    'year_month' => $yearMonth,
                    'amount' => $amount,
                    'journal_entry_id' => $entry->id,
                    'posted_at' => now(),
                ]);

                $newAccum = (int) $asset->accumulated_depreciation + $amount;
                $status = $newAccum >= $asset->depreciableBase()
                    ? FixedAssetStatus::FullyDepreciated->value
                    : FixedAssetStatus::Active->value;
                $asset->update(['accumulated_depreciation' => $newAccum, 'status' => $status]);
            });

            $processed++;
            $total += $amount;
        }

        return ['processed' => $processed, 'total' => $total, 'skipped' => $skipped];
    }
}
