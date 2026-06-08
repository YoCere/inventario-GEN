<?php

namespace App\Livewire\Boms;

use App\Models\BillOfMaterial;
use Illuminate\Database\Eloquent\Builder;
use PowerComponents\LivewirePowerGrid\Button;
use PowerComponents\LivewirePowerGrid\Column;
use PowerComponents\LivewirePowerGrid\PowerGridFields;
use PowerComponents\LivewirePowerGrid\Facades\PowerGrid;
use PowerComponents\LivewirePowerGrid\Traits\WithExport;
use PowerComponents\LivewirePowerGrid\PowerGridComponent;
use PowerComponents\LivewirePowerGrid\Components\SetUp\Exportable;

final class BomTable extends PowerGridComponent
{
    use WithExport;

    public string $tableName = 'bom-table';
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
            PowerGrid::exportable('recetas_bom_' . now()->format('Y_m_d'))
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
        return BillOfMaterial::query()->with('product');
    }

    public function fields(): PowerGridFields
    {
        return PowerGrid::fields()
            ->add('id')
            ->add('product_name', fn (BillOfMaterial $model) => $model->product?->name ?? '-')
            ->add('mod_fmt', fn (BillOfMaterial $model) => format_money($model->mod_rate))
            ->add('moi_fmt', fn (BillOfMaterial $model) => format_money($model->moi_rate))
            ->add('cif_fmt', fn (BillOfMaterial $model) => format_money($model->cif_rate))
            ->add('components_count', fn (BillOfMaterial $model) => $model->components()->count())
            ->add('active_badge', function (BillOfMaterial $model) {
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

            Column::make('Producto', 'product_name')
                ->sortable()
                ->searchable(),

            Column::make('MOD (Bs)', 'mod_fmt'),

            Column::make('MOI (Bs)', 'moi_fmt'),

            Column::make('CIF (Bs)', 'cif_fmt'),

            Column::make('Componentes', 'components_count'),

            Column::make('Estado', 'active_badge'),
        ];
    }

    public function filters(): array
    {
        return [];
    }

    public function actions(BillOfMaterial $row): array
    {
        return [
            Button::add('edit')
                ->slot('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" /></svg>')
                ->class('bg-amber-500 hover:bg-amber-600 text-white p-2 rounded-md flex items-center justify-center')
                ->dispatch('edit-bom', ['bomId' => $row->id])
                ->tooltip('Editar receta'),
        ];
    }
}
