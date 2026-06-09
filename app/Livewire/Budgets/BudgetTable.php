<?php

namespace App\Livewire\Budgets;

use App\Models\Budget;
use Illuminate\Database\Eloquent\Builder;
use PowerComponents\LivewirePowerGrid\Button;
use PowerComponents\LivewirePowerGrid\Column;
use PowerComponents\LivewirePowerGrid\PowerGridFields;
use PowerComponents\LivewirePowerGrid\Facades\PowerGrid;
use PowerComponents\LivewirePowerGrid\Traits\WithExport;
use PowerComponents\LivewirePowerGrid\PowerGridComponent;
use PowerComponents\LivewirePowerGrid\Components\SetUp\Exportable;

final class BudgetTable extends PowerGridComponent
{
    use WithExport;

    public string $tableName = 'budget-table';
    public string $sortField = 'created_at';
    public string $sortDirection = 'desc';

    public function boot(): void
    {
        config(['livewire-powergrid.filter' => 'outside']);
    }

    public function setUp(): array
    {
        return [
            PowerGrid::exportable('presupuestos_' . now()->format('Y_m_d'))
                ->type(Exportable::TYPE_XLS, Exportable::TYPE_CSV),

            PowerGrid::header()
                ->showSearchInput(),

            PowerGrid::footer()
                ->showPerPage(perPage: 10, perPageValues: [10, 25, 50, 100])
                ->showRecordCount(),
        ];
    }

    public function datasource(): Builder
    {
        return Budget::query();
    }

    public function fields(): PowerGridFields
    {
        return PowerGrid::fields()
            ->add('id')
            ->add('name')
            ->add('base_from', fn (Budget $model) => $model->base_from->format('d/m/Y'))
            ->add('base_to', fn (Budget $model) => $model->base_to->format('d/m/Y'))
            ->add('years')
            ->add('growth_fmt', fn (Budget $model) => $model->growth_pct . '%')
            ->add('active_badge', function (Budget $model) {
                if ($model->is_active) {
                    return "<span class='inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800'>Activo</span>";
                }
                return "<span class='inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800'>Inactivo</span>";
            })
            ->add('created_at');
    }

    public function columns(): array
    {
        return [
            Column::action('Acción'),

            Column::make('ID', 'id')
                ->hidden()
                ->visibleInExport(true),

            Column::make('Nombre', 'name')
                ->sortable()
                ->searchable(),

            Column::make('Desde', 'base_from')
                ->sortable(),

            Column::make('Hasta', 'base_to')
                ->sortable(),

            Column::make('Años', 'years')
                ->sortable(),

            Column::make('Crecimiento', 'growth_fmt'),

            Column::make('Estado', 'active_badge'),
        ];
    }

    public function filters(): array
    {
        return [];
    }

    public function actions(Budget $row): array
    {
        return [
            Button::add('view')
                ->slot('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>')
                ->class('bg-blue-500 hover:bg-blue-600 text-white p-2 rounded-md flex items-center justify-center')
                ->route('finance.budgets.show', ['budget' => $row->id])
                ->tooltip('Ver proyección'),
        ];
    }
}
