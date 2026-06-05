<?php

namespace App\Livewire\FixedAssets;

use App\Enums\FixedAssetStatus;
use App\Models\FixedAsset;
use Illuminate\Database\Eloquent\Builder;
use PowerComponents\LivewirePowerGrid\Button;
use PowerComponents\LivewirePowerGrid\Column;
use PowerComponents\LivewirePowerGrid\Facades\Filter;
use PowerComponents\LivewirePowerGrid\PowerGridFields;
use PowerComponents\LivewirePowerGrid\Facades\PowerGrid;
use PowerComponents\LivewirePowerGrid\Traits\WithExport;
use PowerComponents\LivewirePowerGrid\PowerGridComponent;
use PowerComponents\LivewirePowerGrid\Components\SetUp\Exportable;

final class FixedAssetTable extends PowerGridComponent
{
    use WithExport;

    public string $tableName = 'fixed-asset-table';
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
            PowerGrid::exportable('activos_fijos_' . now()->format('Y_m_d'))
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
        return FixedAsset::query()->with('category');
    }

    public function fields(): PowerGridFields
    {
        return PowerGrid::fields()
            ->add('id')
            ->add('code')
            ->add('name')
            ->add('category_name', fn (FixedAsset $model) => $model->category?->name ?? '-')
            ->add('acquisition_cost_formatted', fn (FixedAsset $model) => format_money($model->acquisition_cost))
            ->add('accumulated_depreciation_formatted', fn (FixedAsset $model) => format_money($model->accumulated_depreciation))
            ->add('book_value_formatted', fn (FixedAsset $model) => format_money($model->bookValue()))
            ->add('status_badge', function (FixedAsset $model) {
                $colors = [
                    FixedAssetStatus::Active->value            => 'bg-green-100 text-green-800',
                    FixedAssetStatus::FullyDepreciated->value  => 'bg-blue-100 text-blue-800',
                    FixedAssetStatus::Disposed->value          => 'bg-red-100 text-red-800',
                    FixedAssetStatus::NotDepreciable->value    => 'bg-gray-100 text-gray-800',
                ];
                $color = $colors[$model->status->value] ?? 'bg-gray-100 text-gray-800';
                $label = $model->status->label();
                return "<span class='inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {$color}'>{$label}</span>";
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

            Column::make('Código', 'code')
                ->sortable()
                ->searchable(),

            Column::make('Nombre', 'name')
                ->sortable()
                ->searchable(),

            Column::make('Categoría', 'category_name')
                ->sortable()
                ->searchable(),

            Column::make('Costo adq.', 'acquisition_cost_formatted'),

            Column::make('Dep. acum.', 'accumulated_depreciation_formatted'),

            Column::make('Valor libro', 'book_value_formatted'),

            Column::make('Estado', 'status_badge')
                ->html(),
        ];
    }

    public function filters(): array
    {
        return [];
    }

    public function actions(FixedAsset $row): array
    {
        $actions = [];

        // Ver cédula de depreciación
        $scheduleUrl = route('finance.fixed-assets.schedule', $row->id);
        $actions[] = Button::add('schedule')
            ->slot('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" /></svg>')
            ->class('bg-blue-500 hover:bg-blue-600 text-white p-2 rounded-md flex items-center justify-center')
            ->link($scheduleUrl)
            ->tooltip('Ver Cédula de Depreciación');

        // Baja — solo si el activo no está ya dado de baja
        if ($row->status !== FixedAssetStatus::Disposed) {
            $actions[] = Button::add('dispose')
                ->slot('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>')
                ->class('bg-red-500 hover:bg-red-600 text-white p-2 rounded-md flex items-center justify-center')
                ->dispatch('dispose-fixed-asset', ['assetId' => $row->id])
                ->tooltip('Dar de Baja');
        }

        return $actions;
    }
}
