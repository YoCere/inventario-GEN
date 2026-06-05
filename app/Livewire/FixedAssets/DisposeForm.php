<?php

namespace App\Livewire\FixedAssets;

use Livewire\Component;
use Livewire\Attributes\On;
use App\Models\FixedAsset;
use App\Models\ChartOfAccount;
use App\Services\Accounting\FixedAssetService;

class DisposeForm extends Component
{
    public ?int $assetId = null;
    public string $disposal_date = '';
    public float $sale_amount = 0;
    public string $cash_account_code = '';
    public string $result_account_code = '';

    public function rules(): array
    {
        return [
            'assetId'             => ['required', 'exists:fixed_assets,id'],
            'disposal_date'       => ['required', 'date'],
            'sale_amount'         => ['numeric', 'min:0'],
            'cash_account_code'   => ['nullable', 'exists:chart_of_accounts,code'],
            'result_account_code' => ['required', 'exists:chart_of_accounts,code'],
        ];
    }

    public function getAccountOptionsProperty(): array
    {
        return ChartOfAccount::where('allows_posting', true)
            ->orderBy('code')
            ->get()
            ->map(fn ($a) => ['value' => $a->code, 'label' => $a->code . ' - ' . $a->name])
            ->toArray();
    }

    public function render()
    {
        $asset = $this->assetId ? FixedAsset::find($this->assetId) : null;
        return view('livewire.fixed-assets.dispose-form', [
            'asset'          => $asset,
            'accountOptions' => $this->accountOptions,
        ]);
    }

    #[On('dispose-fixed-asset')]
    public function open(int $assetId): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $this->assetId = $assetId;
        $this->disposal_date = now()->toDateString();
        $this->sale_amount = 0;
        $this->cash_account_code = '';
        $this->result_account_code = '';
        $this->dispatch('open-modal', name: 'dispose-form-modal');
    }

    public function save(FixedAssetService $service): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $this->validate();

        $asset = FixedAsset::findOrFail($this->assetId);
        $service->dispose(
            $asset,
            $this->disposal_date,
            (int) round($this->sale_amount * 100),
            $this->cash_account_code ?: null,
            $this->result_account_code,
            auth()->id()
        );

        $this->dispatch('close-modal', name: 'dispose-form-modal');
        $this->dispatch('pg:eventRefresh-fixed-asset-table');
        $this->dispatch('toast', message: 'Activo fijo dado de baja correctamente.', type: 'success');
    }
}
