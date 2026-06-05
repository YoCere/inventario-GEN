<?php

namespace App\Livewire\AssetCategories;

use Livewire\Component;
use Livewire\Attributes\On;
use App\Models\AssetCategory;
use App\Models\ChartOfAccount;

class AssetCategoryForm extends Component
{
    public bool $isEditing = false;
    public ?int $categoryId = null;

    public string $name = '';
    public int $useful_life_months = 60;
    public float $annual_rate_pct = 0;
    public bool $is_deferred = false;
    public string $ppe_account_code = '';
    public string $accumulated_account_code = '';
    public string $expense_account_code = '';

    public function rules(): array
    {
        return [
            'name'                     => ['required', 'string', 'max:255'],
            'useful_life_months'       => ['required', 'integer', 'min:1'],
            'annual_rate_pct'          => ['numeric', 'min:0'],
            'is_deferred'              => ['boolean'],
            'ppe_account_code'         => ['required', 'exists:chart_of_accounts,code'],
            'accumulated_account_code' => ['required', 'exists:chart_of_accounts,code'],
            'expense_account_code'     => ['required', 'exists:chart_of_accounts,code'],
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
        return view('livewire.asset-categories.asset-category-form', [
            'accountOptions' => $this->accountOptions,
        ]);
    }

    #[On('create-asset-category')]
    public function create(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $this->reset(['name', 'useful_life_months', 'annual_rate_pct', 'is_deferred',
            'ppe_account_code', 'accumulated_account_code', 'expense_account_code',
            'categoryId', 'isEditing']);
        $this->useful_life_months = 60;
        $this->annual_rate_pct = 0;
        $this->dispatch('open-modal', name: 'asset-category-form-modal');
    }

    #[On('edit-asset-category')]
    public function edit(int $categoryId): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $category = AssetCategory::findOrFail($categoryId);
        $this->categoryId = $category->id;
        $this->name = $category->name;
        $this->useful_life_months = $category->useful_life_months;
        $this->annual_rate_pct = (float) $category->annual_rate_pct;
        $this->is_deferred = (bool) $category->is_deferred;
        $this->ppe_account_code = $category->ppe_account_code;
        $this->accumulated_account_code = $category->accumulated_account_code;
        $this->expense_account_code = $category->expense_account_code;
        $this->isEditing = true;
        $this->dispatch('open-modal', name: 'asset-category-form-modal');
    }

    public function save(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $this->validate();

        AssetCategory::updateOrCreate(
            ['id' => $this->categoryId],
            [
                'name'                     => $this->name,
                'useful_life_months'       => $this->useful_life_months,
                'annual_rate_pct'          => $this->annual_rate_pct,
                'is_deferred'              => $this->is_deferred,
                'ppe_account_code'         => $this->ppe_account_code,
                'accumulated_account_code' => $this->accumulated_account_code,
                'expense_account_code'     => $this->expense_account_code,
                'is_active'               => true,
            ]
        );

        $this->dispatch('close-modal', name: 'asset-category-form-modal');
        $this->dispatch('pg:eventRefresh-asset-category-table');
        $this->dispatch('toast', message: $this->isEditing
            ? 'Categoría de activo actualizada correctamente.'
            : 'Categoría de activo creada correctamente.', type: 'success');
    }
}
