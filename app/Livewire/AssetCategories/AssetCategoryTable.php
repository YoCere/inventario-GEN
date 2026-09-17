<?php

namespace App\Livewire\AssetCategories;

use App\Support\Ui\Tone;
use App\Models\AssetCategory;
use Illuminate\Database\Eloquent\Builder;
use PowerComponents\LivewirePowerGrid\Button;
use PowerComponents\LivewirePowerGrid\Column;
use PowerComponents\LivewirePowerGrid\Facades\Filter;
use PowerComponents\LivewirePowerGrid\PowerGridFields;
use PowerComponents\LivewirePowerGrid\Facades\PowerGrid;
use PowerComponents\LivewirePowerGrid\Traits\WithExport;
use PowerComponents\LivewirePowerGrid\PowerGridComponent;
use PowerComponents\LivewirePowerGrid\Components\SetUp\Exportable;

final class AssetCategoryTable extends PowerGridComponent
{
    use WithExport;

    public string $tableName = 'asset-category-table';
    public string $sortField = 'created_at';
    public string $sortDirection = 'desc';

    public function boot(): void
    {
        config(['livewire-powergrid.filter' => 'outside']);
    }

    public function setUp(): array
    {
        $this->showCheckBox();

        return [
            PowerGrid::exportable('categorias_activo_' . now()->format('Y_m_d'))
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
        return AssetCategory::query();
    }

    public function fields(): PowerGridFields
    {
        return PowerGrid::fields()
            ->add('id')
            ->add('name')
            ->add('useful_life_months')
            ->add('annual_rate_pct')
            ->add('is_deferred_badge', function (AssetCategory $model) {
                return $model->is_deferred
                    ? Tone::badgeHtml(Tone::INFO, 'Sí')
                    : Tone::badgeHtml(Tone::NEUTRAL, 'No');
            })
            ->add('ppe_account_code')
            ->add('accumulated_account_code')
            ->add('expense_account_code')
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

            Column::make('Vida útil (meses)', 'useful_life_months')
                ->sortable(),

            Column::make('Tasa anual (%)', 'annual_rate_pct')
                ->sortable(),

            Column::make('Diferido', 'is_deferred_badge'),

            Column::make('Cta. PPE', 'ppe_account_code')
                ->sortable()
                ->searchable(),

            Column::make('Cta. Dep. Acum.', 'accumulated_account_code')
                ->sortable()
                ->searchable(),

            Column::make('Cta. Gasto', 'expense_account_code')
                ->sortable()
                ->searchable(),
        ];
    }

    public function filters(): array
    {
        return [];
    }

    public function actions(AssetCategory $row): array
    {
        return [
            Button::add('edit')
                ->slot('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>')
                ->class('border border-border bg-background text-foreground hover:bg-muted p-2 rounded-md flex items-center justify-center')
                ->dispatch('edit-asset-category', ['categoryId' => $row->id])
                ->tooltip('Editar Categoría'),
        ];
    }
}
