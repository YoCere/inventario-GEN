<?php

namespace App\Livewire\Production;

use App\Models\ProductionOrder;
use Illuminate\Database\Eloquent\Builder;
use PowerComponents\LivewirePowerGrid\Column;
use PowerComponents\LivewirePowerGrid\PowerGridFields;
use PowerComponents\LivewirePowerGrid\Facades\PowerGrid;
use PowerComponents\LivewirePowerGrid\Traits\WithExport;
use PowerComponents\LivewirePowerGrid\PowerGridComponent;
use PowerComponents\LivewirePowerGrid\Components\SetUp\Exportable;

final class ProductionOrderTable extends PowerGridComponent
{
    use WithExport;

    public string $tableName = 'production-order-table';
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
            PowerGrid::exportable('ordenes_produccion_' . now()->format('Y_m_d'))
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
        return ProductionOrder::query()->with('product');
    }

    public function fields(): PowerGridFields
    {
        return PowerGrid::fields()
            ->add('id')
            ->add('code')
            ->add('product_name', fn (ProductionOrder $model) => $model->product?->name ?? '-')
            ->add('quantity')
            ->add('total_fmt', fn (ProductionOrder $model) => format_money($model->total_cost))
            ->add('unit_fmt', fn (ProductionOrder $model) => format_money($model->unit_cost))
            ->add('production_date', fn (ProductionOrder $model) => $model->production_date?->format('Y-m-d') ?? '-')
            ->add('status_badge', function (ProductionOrder $model) {
                $color = match ($model->status) {
                    'completed' => 'bg-green-100 text-green-800',
                    'cancelled' => 'bg-red-100 text-red-800',
                    default     => 'bg-gray-100 text-gray-800',
                };
                $label = match ($model->status) {
                    'completed' => 'Completada',
                    'cancelled' => 'Cancelada',
                    default     => ucfirst($model->status),
                };
                return "<span class='inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {$color}'>{$label}</span>";
            })
            ->add('created_at');
    }

    public function columns(): array
    {
        return [
            Column::make('ID', 'id')
                ->hidden()
                ->visibleInExport(true),

            Column::make('Código', 'code')
                ->sortable()
                ->searchable(),

            Column::make('Producto', 'product_name')
                ->sortable()
                ->searchable(),

            Column::make('Cantidad', 'quantity')
                ->sortable(),

            Column::make('Costo total (Bs)', 'total_fmt'),

            Column::make('Costo unit. (Bs)', 'unit_fmt'),

            Column::make('Fecha producción', 'production_date')
                ->sortable(),

            Column::make('Estado', 'status_badge'),
        ];
    }

    public function filters(): array
    {
        return [];
    }
}
