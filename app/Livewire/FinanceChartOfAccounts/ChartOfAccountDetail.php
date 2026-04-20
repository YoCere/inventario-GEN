<?php

namespace App\Livewire\FinanceChartOfAccounts;

use Livewire\Component;
use Livewire\Attributes\On;
use App\Models\ChartOfAccount;

class ChartOfAccountDetail extends Component
{
    public ?ChartOfAccount $account = null;

    #[On('view-chart-account')]
    public function show(ChartOfAccount $account): void
    {
        $this->account = $account->load('parent', 'children');
        $this->dispatch('open-modal', name: 'chart-of-account-detail-modal');
    }

    public function render()
    {
        return view('livewire.finance-chart-of-accounts.chart-of-account-detail');
    }
}
