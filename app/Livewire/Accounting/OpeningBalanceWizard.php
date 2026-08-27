<?php

namespace App\Livewire\Accounting;

use App\DTOs\OpeningBalanceData;
use App\Services\Accounting\OpeningBalanceService;
use Livewire\Component;

class OpeningBalanceWizard extends Component
{
    public string $date = '';
    public $cash = 0, $bank = 0, $receivable = 0, $inventory = 0, $ppe = 0,
           $accumulated_depreciation = 0, $payable = 0, $loans = 0, $capital = 0;

    public function mount(OpeningBalanceService $service): void
    {
        abort_if(! auth()->user()?->isAdmin(), 403);
        $this->date = now()->startOfYear()->toDateString();
        $p = $service->propose($this->date);
        $this->inventory = $p->inventory / 100;
        $this->ppe = $p->ppe / 100;
        $this->accumulated_depreciation = $p->accumulated_depreciation / 100;
        $this->loans = $p->loans / 100;
        $this->capital = $p->capital / 100;
    }

    public function getBalancingCapitalProperty(): float
    {
        return ($this->cash + $this->bank + $this->receivable + $this->inventory + $this->ppe)
            - $this->accumulated_depreciation - $this->payable - $this->loans;
    }

    public function save(OpeningBalanceService $service): void
    {
        abort_if(! auth()->user()?->isAdmin(), 403);
        $this->validate(['date' => 'required|date']);

        $toCents = fn ($v) => (int) round(((float) $v) * 100);
        $data = OpeningBalanceData::fromArray([
            'cash' => $toCents($this->cash), 'bank' => $toCents($this->bank),
            'receivable' => $toCents($this->receivable), 'inventory' => $toCents($this->inventory),
            'ppe' => $toCents($this->ppe), 'accumulated_depreciation' => $toCents($this->accumulated_depreciation),
            'payable' => $toCents($this->payable), 'loans' => $toCents($this->loans),
            'capital' => $toCents($this->capital),
        ]);

        try {
            $service->post($data, $this->date, auth()->id());
            session()->flash('ok', 'Asiento de apertura registrado.');
            $this->dispatch('toast', message: 'Apertura registrada.', type: 'success');
        } catch (\Throwable $e) {
            $this->addError('date', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.accounting.opening-balance-wizard');
    }
}
