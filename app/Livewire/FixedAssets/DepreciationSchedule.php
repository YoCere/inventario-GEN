<?php

namespace App\Livewire\FixedAssets;

use App\Models\FixedAsset;
use Livewire\Component;

class DepreciationSchedule extends Component
{
    public int $assetId;

    public function mount(int $assetId): void
    {
        $this->assetId = $assetId;
    }

    public function render()
    {
        $asset = FixedAsset::with(['depreciationRuns', 'category'])->findOrFail($this->assetId);

        $rows = [];
        $runningAccumulated = 0;

        foreach ($asset->depreciationRuns->sortBy('year_month') as $run) {
            $runningAccumulated += $run->amount;
            $rows[] = [
                'year_month'           => $run->year_month,
                'amount'               => $run->amount,
                'running_accumulated'  => $runningAccumulated,
                'running_book_value'   => $asset->acquisition_cost - $runningAccumulated,
                'journal_entry_id'     => $run->journal_entry_id,
            ];
        }

        return view('livewire.fixed-assets.depreciation-schedule', [
            'asset' => $asset,
            'rows'  => $rows,
        ]);
    }
}
