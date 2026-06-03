<?php

namespace App\Livewire\FinanceChartOfAccounts;

use App\Models\ChartOfAccount;
use App\Enums\AccountType;
use App\Services\Accounting\ChartOfAccountService;
use Illuminate\Database\Eloquent\Builder;
use PowerComponents\LivewirePowerGrid\Button;
use PowerComponents\LivewirePowerGrid\Column;
use PowerComponents\LivewirePowerGrid\Facades\Filter;
use PowerComponents\LivewirePowerGrid\PowerGridFields;
use PowerComponents\LivewirePowerGrid\Facades\PowerGrid;
use PowerComponents\LivewirePowerGrid\Traits\WithExport;
use PowerComponents\LivewirePowerGrid\PowerGridComponent;
use PowerComponents\LivewirePowerGrid\Components\SetUp\Exportable;

final class ChartOfAccountTable extends PowerGridComponent
{
    use WithExport;

    public string $tableName = 'chart-of-account-table';
    public string $sortField = 'code';
    public string $sortDirection = 'asc';

    public function boot(): void
    {
        config(['livewire-powergrid.filter' => 'outside']);
    }

    public function setUp(): array
    {
        return [
            PowerGrid::exportable('plan_de_cuentas_' . now()->format('Y_m_d'))
                ->type(Exportable::TYPE_XLS, Exportable::TYPE_CSV),

            PowerGrid::header()
                ->showSearchInput(),

            PowerGrid::footer()
                ->showPerPage(perPage: 20, perPageValues: [20, 50, 100])
                ->showRecordCount(),
        ];
    }

    public function datasource(): Builder
    {
        return ChartOfAccount::query()->with('parent');
    }

    public function fields(): PowerGridFields
    {
        return PowerGrid::fields()
            ->add('id')
            ->add('code')
            ->add('name')
            ->add('level')
            ->add('parent_name', fn (ChartOfAccount $model) => $model->parent?->code . ' - ' . $model->parent?->name ?: '-')
            ->add('account_type')
            ->add('account_type_label', fn (ChartOfAccount $model) => $model->account_type->label())
            ->add('normal_balance')
            ->add('normal_balance_label', fn (ChartOfAccount $model) => $model->normal_balance->label())
            ->add('allows_posting_label', fn (ChartOfAccount $model) => $model->allows_posting ? 'Si' : 'No')
            ->add('is_active_label', fn (ChartOfAccount $model) => $model->is_active ? 'Activo' : 'Inactivo');
    }

    public function columns(): array
    {
        return [
            Column::action('Accion'),

            Column::make('Codigo', 'code')
                ->sortable()
                ->searchable(),

            Column::make('Nombre', 'name')
                ->sortable()
                ->searchable(),

            Column::make('Nivel', 'level')
                ->sortable(),

            Column::make('Cuenta padre', 'parent_name'),

            Column::make('Tipo', 'account_type_label', 'account_type')
                ->sortable(),

            Column::make('Naturaleza', 'normal_balance_label', 'normal_balance')
                ->sortable(),

            Column::make('Imputable', 'allows_posting_label', 'allows_posting')
                ->sortable(),

            Column::make('Estado', 'is_active_label', 'is_active')
                ->sortable(),

        ];
    }

    public function filters(): array
    {
        return [
            Filter::select('account_type', 'account_type')
                ->dataSource(collect(AccountType::cases())->map(fn ($type) => [
                    'value' => $type->value,
                    'label' => $type->label(),
                ])->toArray())
                ->optionLabel('label')
                ->optionValue('value'),

            Filter::select('is_active', 'is_active')
                ->dataSource([
                    ['value' => 1, 'label' => 'Activo'],
                    ['value' => 0, 'label' => 'Inactivo'],
                ])
                ->optionLabel('label')
                ->optionValue('value'),

            Filter::select('allows_posting', 'allows_posting')
                ->dataSource([
                    ['value' => 1, 'label' => 'Si'],
                    ['value' => 0, 'label' => 'No'],
                ])
                ->optionLabel('label')
                ->optionValue('value'),
        ];
    }

    public function actions(ChartOfAccount $row): array
    {
        $toggleLabel = $row->is_active ? 'Desactivar' : 'Activar';
        $toggleClass = $row->is_active
            ? 'bg-red-500 hover:bg-red-600 text-white px-3 py-2 rounded-md text-xs font-medium'
            : 'bg-green-500 hover:bg-green-600 text-white px-3 py-2 rounded-md text-xs font-medium';

        return [
            Button::add('view')
                ->slot('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>')
                ->class('bg-blue-500 hover:bg-blue-600 text-white p-2 rounded-md flex items-center justify-center')
                ->dispatch('view-chart-account', ['account' => $row->id])
                ->tooltip('Ver Cuenta'),

            Button::add('edit')
                ->slot('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>')
                ->class('bg-amber-500 hover:bg-amber-600 text-white p-2 rounded-md flex items-center justify-center')
                ->dispatch('edit-chart-account', ['account' => $row->id])
                ->tooltip('Editar Cuenta'),

            Button::add('toggle-active')
                ->slot($toggleLabel)
                ->class($toggleClass)
                ->dispatch('toggle-chart-account-active', ['id' => $row->id])
                ->tooltip($row->is_active ? 'Desactivar Cuenta' : 'Activar Cuenta'),
        ];
    }

    #[\Livewire\Attributes\On('toggle-chart-account-active')]
    public function toggleActive(int $id): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $account = ChartOfAccount::findOrFail($id);
        app(ChartOfAccountService::class)->setActive($account, !$account->is_active);
        $this->dispatch('toast', message: 'Estado de la cuenta actualizado.', type: 'success');
    }

    #[\Livewire\Attributes\On('chart-account-saved')]
    public function refreshAfterSave(): void
    {
        $this->dispatch('pg:eventRefresh-' . $this->tableName);
    }
}
