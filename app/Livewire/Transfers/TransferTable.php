<?php

namespace App\Livewire\Transfers;

use App\Models\StockTransfer;
use App\Services\TransferService;
use Illuminate\Database\Eloquent\Builder;
use PowerComponents\LivewirePowerGrid\Button;
use PowerComponents\LivewirePowerGrid\Column;
use PowerComponents\LivewirePowerGrid\PowerGridFields;
use PowerComponents\LivewirePowerGrid\Facades\PowerGrid;
use PowerComponents\LivewirePowerGrid\Traits\WithExport;
use PowerComponents\LivewirePowerGrid\PowerGridComponent;
use PowerComponents\LivewirePowerGrid\Components\SetUp\Exportable;

final class TransferTable extends PowerGridComponent
{
    use WithExport;

    public string $tableName = 'transfer-table';
    public string $sortField = 'created_at';
    public string $sortDirection = 'desc';

    public function setUp(): array
    {
        return [
            PowerGrid::exportable('transfers_export_' . now()->format('Y_m_d'))
                ->type(Exportable::TYPE_XLS, Exportable::TYPE_CSV),

            PowerGrid::header()->showSearchInput(),

            PowerGrid::footer()
                ->showPerPage(perPage: 10, perPageValues: [10, 25, 50, 100])
                ->showRecordCount(),
        ];
    }

    public function datasource(): Builder
    {
        return StockTransfer::query()
            ->with(['fromLocation.warehouse', 'toLocation.warehouse', 'creator'])
            ->withCount('items');
    }

    public function fields(): PowerGridFields
    {
        return PowerGrid::fields()
            ->add('id')
            ->add('reference')
            ->add('from_name', fn ($row) => ($row->fromLocation?->warehouse?->name ?? '') . ' › ' . ($row->fromLocation?->name ?? '-'))
            ->add('to_name', fn ($row) => ($row->toLocation?->warehouse?->name ?? '') . ' › ' . ($row->toLocation?->name ?? '-'))
            ->add('items_count')
            ->add('creator_name', fn ($row) => $row->creator?->name ?? '-')
            ->add('status_badge', function ($row) {
                $color = match ($row->status) {
                    'completed' => 'bg-green-100 text-green-700',
                    'cancelled' => 'bg-red-100 text-red-700',
                    default => 'bg-amber-100 text-amber-700',
                };
                $label = match ($row->status) {
                    'completed' => 'Completada',
                    'cancelled' => 'Cancelada',
                    default => 'Borrador',
                };
                return "<span class=\"px-2 py-1 text-xs rounded-full {$color}\">{$label}</span>";
            })
            ->add('created_at_formatted', fn ($row) => $row->created_at?->format('d/m/Y H:i'));
    }

    public function columns(): array
    {
        return [
            Column::action('Acción'),
            Column::make('ID', 'id')->hidden()->visibleInExport(true),
            Column::make('Referencia', 'reference')->sortable()->searchable(),
            Column::make('Desde', 'from_name')->searchable(),
            Column::make('Hacia', 'to_name')->searchable(),
            Column::make('Items', 'items_count')->sortable(),
            Column::make('Estado', 'status_badge', 'status')->sortable(),
            Column::make('Creado por', 'creator_name'),
            Column::make('Fecha', 'created_at_formatted', 'created_at')->sortable(),
        ];
    }

    public function actions(StockTransfer $row): array
    {
        $actions = [];

        if ($row->isDraft() && auth()->user()->isAdmin()) {
            $actions[] = Button::add('complete')
                ->slot('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>')
                ->class('bg-green-500 hover:bg-green-600 text-white p-2 rounded-md flex items-center justify-center')
                ->dispatch('confirm-complete-transfer', ['id' => $row->id])
                ->tooltip('Ejecutar transferencia');

            $actions[] = Button::add('cancel')
                ->slot('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>')
                ->class('bg-red-500 hover:bg-red-600 text-white p-2 rounded-md flex items-center justify-center')
                ->dispatch('confirm-cancel-transfer', ['id' => $row->id])
                ->tooltip('Cancelar transferencia');
        }

        $actions[] = Button::add('view')
            ->slot('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>')
            ->class('bg-blue-500 hover:bg-blue-600 text-white p-2 rounded-md flex items-center justify-center')
            ->dispatch('view-transfer', ['id' => $row->id])
            ->tooltip('Ver detalle');

        return $actions;
    }

    #[\Livewire\Attributes\On('confirm-complete-transfer')]
    public function complete($id, TransferService $service): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);

        $transfer = StockTransfer::find($id);
        if (!$transfer) return;

        try {
            $service->completeTransfer($transfer);
            $this->dispatch('toast', message: "Transferencia {$transfer->reference} completada.", type: 'success');
            $this->dispatch('pg:eventRefresh-transfer-table');
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Error: ' . $e->getMessage(), type: 'error');
        }
    }

    #[\Livewire\Attributes\On('confirm-cancel-transfer')]
    public function cancel($id, TransferService $service): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);

        $transfer = StockTransfer::find($id);
        if (!$transfer) return;

        try {
            $service->cancelTransfer($transfer, 'Cancelada vía UI');
            $this->dispatch('toast', message: "Transferencia {$transfer->reference} cancelada.", type: 'success');
            $this->dispatch('pg:eventRefresh-transfer-table');
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Error: ' . $e->getMessage(), type: 'error');
        }
    }
}
