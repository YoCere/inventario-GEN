<?php

namespace App\Livewire\Accounting;

use App\Models\AccountingPeriod;
use App\Services\Accounting\TrialBalanceService;
use Livewire\Component;

class TrialBalance extends Component
{
    public ?int $periodId = null;
    public bool $adjusted = false;

    public function mount(): void
    {
        $this->periodId ??= AccountingPeriod::orderByDesc('start_date')->value('id');
    }

    public function render()
    {
        $period = $this->periodId ? AccountingPeriod::find($this->periodId) : null;
        $data = $period ? app(TrialBalanceService::class)->build($period, $this->adjusted) : null;

        return view('livewire.accounting.trial-balance', [
            'period'  => $period,
            'data'    => $data,
            'periods' => AccountingPeriod::orderByDesc('start_date')->get(),
        ]);
    }
}
