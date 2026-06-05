<?php

namespace App\Services\Accounting;

use App\Enums\FixedAssetStatus;
use App\Models\AccountingPeriod;
use App\Models\AssetCategory;
use App\Models\ChartOfAccount;
use App\Models\FixedAsset;
use Illuminate\Support\Facades\DB;

class FixedAssetService
{
    public function __construct(private JournalEntryService $journal)
    {
    }

    public function registerNew(array $data, string $fundingAccountCode, int $userId): FixedAsset
    {
        return DB::transaction(function () use ($data, $fundingAccountCode, $userId) {
            $asset = FixedAsset::create($data + [
                'status' => FixedAssetStatus::Active->value,
                'is_opening' => false,
                'accumulated_depreciation' => 0,
            ]);

            $cat = AssetCategory::findOrFail($asset->asset_category_id);
            $ppe = ChartOfAccount::where('code', $cat->ppe_account_code)->firstOrFail();
            $funding = ChartOfAccount::where('code', $fundingAccountCode)->firstOrFail();
            $period = AccountingPeriod::resolveOpenForDate($asset->acquisition_date->toDateString());

            $entry = $this->journal->createPostedEntry([
                'entry_date' => $asset->acquisition_date->toDateString(),
                'accounting_period_id' => $period->id,
                'description' => "Compra activo fijo {$asset->code} {$asset->name}",
                'entry_type' => 'normal',
                'voucher_type' => 'egreso',
                'created_by' => $userId,
            ], [
                ['chart_of_account_id' => $ppe->id, 'debit_amount' => (int) $asset->acquisition_cost, 'credit_amount' => 0],
                ['chart_of_account_id' => $funding->id, 'debit_amount' => 0, 'credit_amount' => (int) $asset->acquisition_cost],
            ]);

            $asset->update(['acquisition_entry_id' => $entry->id]);
            return $asset->fresh();
        });
    }

    public function registerOpening(array $data, int $accumulatedToDate, int $userId): FixedAsset
    {
        $base = (int) $data['acquisition_cost'] - (int) ($data['residual_value'] ?? 0);
        $status = $accumulatedToDate >= $base
            ? FixedAssetStatus::FullyDepreciated->value
            : FixedAssetStatus::Active->value;

        return FixedAsset::create($data + [
            'status' => $status,
            'is_opening' => true,
            'accumulated_depreciation' => $accumulatedToDate,
            'acquisition_entry_id' => null,
        ]);
    }

    public function dispose(FixedAsset $asset, string $date, int $saleAmount, ?string $cashAccountCode, string $resultAccountCode, int $userId): FixedAsset
    {
        if ($asset->status === FixedAssetStatus::Disposed) {
            throw new \RuntimeException("El activo {$asset->code} ya fue dado de baja.");
        }

        return DB::transaction(function () use ($asset, $date, $saleAmount, $cashAccountCode, $resultAccountCode, $userId) {
            $cat = AssetCategory::findOrFail($asset->asset_category_id);
            $ppe = ChartOfAccount::where('code', $cat->ppe_account_code)->firstOrFail();
            $accum = ChartOfAccount::where('code', $cat->accumulated_account_code)->firstOrFail();
            $result = ChartOfAccount::where('code', $resultAccountCode)->firstOrFail();
            $period = AccountingPeriod::resolveOpenForDate($date);

            $cost = (int) $asset->acquisition_cost;
            $acc = (int) $asset->accumulated_depreciation;
            $book = $cost - $acc;
            $gain = $saleAmount - $book;

            $lines = [];
            if ($acc > 0) {
                $lines[] = ['chart_of_account_id' => $accum->id, 'debit_amount' => $acc, 'credit_amount' => 0];
            }
            if ($saleAmount > 0 && $cashAccountCode) {
                $cash = ChartOfAccount::where('code', $cashAccountCode)->firstOrFail();
                $lines[] = ['chart_of_account_id' => $cash->id, 'debit_amount' => $saleAmount, 'credit_amount' => 0];
            }
            if ($gain > 0) {
                $lines[] = ['chart_of_account_id' => $result->id, 'debit_amount' => 0, 'credit_amount' => $gain];
            } elseif ($gain < 0) {
                $lines[] = ['chart_of_account_id' => $result->id, 'debit_amount' => -$gain, 'credit_amount' => 0];
            }
            $lines[] = ['chart_of_account_id' => $ppe->id, 'debit_amount' => 0, 'credit_amount' => $cost];

            $entry = $this->journal->createPostedEntry([
                'entry_date' => $date,
                'accounting_period_id' => $period->id,
                'description' => "Baja activo fijo {$asset->code} {$asset->name}",
                'entry_type' => 'normal',
                'voucher_type' => 'traspaso',
                'created_by' => $userId,
            ], $lines);

            $asset->update([
                'status' => FixedAssetStatus::Disposed->value,
                'disposal_date' => $date,
                'disposal_amount' => $saleAmount,
                'disposal_entry_id' => $entry->id,
            ]);

            return $asset->fresh();
        });
    }
}
