<?php

namespace App\Livewire\FixedAssets;

use Livewire\Component;
use Livewire\Attributes\On;
use App\Models\AssetCategory;
use App\Models\ChartOfAccount;
use App\Services\Accounting\FixedAssetService;

class FixedAssetForm extends Component
{
    public string $mode = 'new'; // 'new' | 'opening'

    public ?int $asset_category_id = null;
    public string $code = '';
    public string $name = '';
    public string $acquisition_date = '';
    public float $acquisition_cost = 0;
    public float $residual_value = 0;
    public int $useful_life_months = 60;
    public string $depreciation_start_date = '';
    public string $funding_account_code = '';
    public float $accumulated_to_date = 0;

    public function rules(): array
    {
        return [
            'mode'                    => ['required', 'in:new,opening'],
            'asset_category_id'       => ['required', 'exists:asset_categories,id'],
            'code'                    => ['required', 'string', 'unique:fixed_assets,code'],
            'name'                    => ['required', 'string', 'max:255'],
            'acquisition_date'        => ['required', 'date'],
            'acquisition_cost'        => ['required', 'numeric', 'min:0.01'],
            'residual_value'          => ['numeric', 'min:0'],
            'useful_life_months'      => ['required', 'integer', 'min:1'],
            'depreciation_start_date' => ['required', 'date'],
            'funding_account_code'    => ['required_if:mode,new', 'nullable', 'exists:chart_of_accounts,code'],
        ];
    }

    public function getCategoryOptionsProperty(): array
    {
        return AssetCategory::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])
            ->toArray();
    }

    public function getAccountOptionsProperty(): array
    {
        return ChartOfAccount::where('allows_posting', true)
            ->orderBy('code')
            ->get()
            ->map(fn ($a) => ['value' => $a->code, 'label' => $a->code . ' - ' . $a->name])
            ->toArray();
    }

    public function updatedAssetCategoryId($value): void
    {
        if ($value) {
            $cat = AssetCategory::find($value);
            if ($cat) {
                $this->useful_life_months = $cat->useful_life_months;
            }
        }
    }

    public function render()
    {
        return view('livewire.fixed-assets.fixed-asset-form', [
            'categoryOptions' => $this->categoryOptions,
            'accountOptions'  => $this->accountOptions,
        ]);
    }

    #[On('create-fixed-asset')]
    public function create(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $this->reset(['asset_category_id', 'code', 'name', 'acquisition_date', 'acquisition_cost',
            'residual_value', 'useful_life_months', 'depreciation_start_date',
            'funding_account_code', 'accumulated_to_date']);
        $this->mode = 'new';
        $this->useful_life_months = 60;
        $this->acquisition_cost = 0;
        $this->residual_value = 0;
        $this->accumulated_to_date = 0;
        $this->dispatch('open-modal', name: 'fixed-asset-form-modal');
    }

    public function save(FixedAssetService $service): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $this->validate();

        $data = [
            'asset_category_id'       => $this->asset_category_id,
            'code'                    => $this->code,
            'name'                    => $this->name,
            'acquisition_date'        => $this->acquisition_date,
            'acquisition_cost'        => (int) round($this->acquisition_cost * 100),
            'residual_value'          => (int) round($this->residual_value * 100),
            'useful_life_months'      => $this->useful_life_months,
            'depreciation_start_date' => $this->depreciation_start_date,
        ];

        if ($this->mode === 'new') {
            $service->registerNew($data, $this->funding_account_code, auth()->id());
        } else {
            $service->registerOpening($data, (int) round($this->accumulated_to_date * 100), auth()->id());
        }

        $this->dispatch('close-modal', name: 'fixed-asset-form-modal');
        $this->dispatch('pg:eventRefresh-fixed-asset-table');
        $this->dispatch('toast', message: 'Activo fijo registrado correctamente.', type: 'success');
    }
}
