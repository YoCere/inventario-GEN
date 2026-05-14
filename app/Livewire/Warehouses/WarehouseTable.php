<?php

namespace App\Livewire\Warehouses;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use PowerComponents\LivewirePowerGrid\Button;
use PowerComponents\LivewirePowerGrid\Column;
use PowerComponents\LivewirePowerGrid\PowerGridFields;
use PowerComponents\LivewirePowerGrid\Facades\PowerGrid;
use PowerComponents\LivewirePowerGrid\Traits\WithExport;
use PowerComponents\LivewirePowerGrid\PowerGridComponent;
use PowerComponents\LivewirePowerGrid\Components\SetUp\Exportable;

final class WarehouseTable extends PowerGridComponent
{
    use WithExport;

    public string $tableName = 'warehouse-table';
    public string $sortField = 'created_at';
    public string $sortDirection = 'desc';

    public function setUp(): array
    {
        return [
            PowerGrid::exportable('warehouses_export_' . now()->format('Y_m_d'))
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
        return Warehouse::query()->withCount('locations');
    }

    public function fields(): PowerGridFields
    {
        return PowerGrid::fields()
            ->add('id')
            ->add('name')
            ->add('address')
            ->add('locations_count')
            ->add('is_active_badge', fn ($row) => $row->is_active
                ? '<span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">Activo</span>'
                : '<span class="px-2 py-1 text-xs rounded-full bg-gray-200 text-gray-700">Inactivo</span>')
            ->add('is_default_badge', fn ($row) => $row->is_default
                ? '<span class="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-700">Predeterminado</span>'
                : '')
            ->add('created_at_formatted', fn ($row) => $row->created_at?->format('d/m/Y'));
    }

    public function columns(): array
    {
        return [
            Column::action('Acción'),

            Column::make('ID', 'id')->hidden()->visibleInExport(true),
            Column::make('Nombre', 'name')->sortable()->searchable(),
            Column::make('Dirección', 'address')->searchable(),
            Column::make('Ubicaciones', 'locations_count')->sortable(),
            Column::make('Estado', 'is_active_badge'),
            Column::make('Default', 'is_default_badge'),
            Column::make('Creado', 'created_at_formatted', 'created_at')->sortable(),
        ];
    }

    public function actions(Warehouse $row): array
    {
        if (!auth()->user()->isAdmin()) {
            return [];
        }

        $actions = [
            Button::add('edit')
                ->slot('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>')
                ->class('bg-amber-500 hover:bg-amber-600 text-white p-2 rounded-md flex items-center justify-center')
                ->dispatch('edit-warehouse', ['warehouse' => $row->id])
                ->tooltip('Editar almacén'),
        ];

        // Cannot delete default warehouse
        if (!$row->is_default) {
            $actions[] = Button::add('delete')
                ->slot('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>')
                ->class('bg-red-500 hover:bg-red-600 text-white p-2 rounded-md flex items-center justify-center')
                ->dispatch('open-delete-modal', [
                    'component' => 'warehouses.warehouse-table',
                    'method' => 'delete',
                    'params' => ['rowId' => $row->id],
                    'title' => 'Eliminar almacén?',
                    'description' => "Seguro que deseas eliminar '{$row->name}'? Se eliminarán también sus ubicaciones. Acción irreversible.",
                ])
                ->tooltip('Eliminar almacén');
        }

        return $actions;
    }

    #[\Livewire\Attributes\On('delete')]
    public function delete($rowId): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);

        $warehouse = Warehouse::find($rowId);

        if (!$warehouse) {
            return;
        }

        if ($warehouse->is_default) {
            $this->dispatch('toast', message: 'No se puede eliminar el almacén predeterminado.', type: 'error');
            return;
        }

        // Check if any product has stock in this warehouse's locations
        $hasStock = \App\Models\ProductStock::whereIn(
            'location_id',
            $warehouse->locations()->pluck('id')
        )->where('quantity', '>', 0)->exists();

        if ($hasStock) {
            $this->dispatch('toast', message: 'No se puede eliminar: hay productos con stock en este almacén.', type: 'error');
            return;
        }

        try {
            $warehouse->delete();
            $this->dispatch('toast', message: 'Almacén eliminado correctamente.', type: 'success');
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Error: ' . $e->getMessage(), type: 'error');
        }
    }
}
