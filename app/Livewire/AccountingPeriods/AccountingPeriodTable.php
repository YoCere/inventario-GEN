<?php

namespace App\Livewire\AccountingPeriods;

use App\Enums\AccountingPeriodStatus;
use App\Models\AccountingPeriod;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;
use PowerComponents\LivewirePowerGrid\Button;
use PowerComponents\LivewirePowerGrid\Column;
use PowerComponents\LivewirePowerGrid\Facades\Filter;
use PowerComponents\LivewirePowerGrid\Facades\PowerGrid;
use PowerComponents\LivewirePowerGrid\PowerGridComponent;
use PowerComponents\LivewirePowerGrid\PowerGridFields;

final class AccountingPeriodTable extends PowerGridComponent
{
    public string $tableName = 'accounting-period-table';
    public string $sortField = 'start_date';
    public string $sortDirection = 'desc';

    public function boot(): void
    {
        config(['livewire-powergrid.filter' => 'outside']);
    }

    public function setUp(): array
    {
        return [
            PowerGrid::header()
                ->showSearchInput(),

            PowerGrid::footer()
                ->showPerPage(perPage: 20, perPageValues: [20, 50])
                ->showRecordCount(),
        ];
    }

    public function datasource(): Builder
    {
        return AccountingPeriod::query()
            ->withCount('journalEntries')
            ->with('closedBy');
    }

    public function fields(): PowerGridFields
    {
        return PowerGrid::fields()
            ->add('id')
            ->add('name')
            ->add('start_date_formatted', fn (AccountingPeriod $model) => $model->start_date->format('d/m/Y'))
            ->add('end_date_formatted', fn (AccountingPeriod $model) => $model->end_date->format('d/m/Y'))
            ->add('status_badge', function (AccountingPeriod $model) {
                if ($model->status === AccountingPeriodStatus::Open) {
                    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">Abierto</span>';
                }
                return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">Cerrado</span>';
            })
            ->add('journal_entries_count')
            ->add('closed_at_formatted', fn (AccountingPeriod $model) => $model->closed_at?->format('d/m/Y H:i') ?? '-')
            ->add('closed_by_name', fn (AccountingPeriod $model) => $model->closedBy?->name ?? '-');
    }

    public function columns(): array
    {
        return [
            Column::action('Accion'),

            Column::make('Periodo', 'name')
                ->sortable()
                ->searchable(),

            Column::make('Inicio', 'start_date_formatted', 'start_date')
                ->sortable(),

            Column::make('Fin', 'end_date_formatted', 'end_date')
                ->sortable(),

            Column::make('Estado', 'status_badge', 'status')
                ->sortable(),

            Column::make('Asientos', 'journal_entries_count')
                ->headerAttribute('text-right')
                ->bodyAttribute('text-right'),

            Column::make('Cerrado el', 'closed_at_formatted', 'closed_at')
                ->sortable(),

            Column::make('Cerrado por', 'closed_by_name'),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::select('status_badge', 'status')
                ->dataSource([
                    ['value' => AccountingPeriodStatus::Open->value, 'label' => 'Abierto'],
                    ['value' => AccountingPeriodStatus::Closed->value, 'label' => 'Cerrado'],
                ])
                ->optionLabel('label')
                ->optionValue('value'),
        ];
    }

    public function actions(AccountingPeriod $row): array
    {
        $actions = [];

        if ($row->status === AccountingPeriodStatus::Open) {
            $actions[] = Button::add('close')
                ->slot('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>')
                ->class('bg-amber-500 hover:bg-amber-600 text-white p-2 rounded-md flex items-center justify-center')
                ->dispatch('open-delete-modal', [
                    'component' => 'accounting-periods.accounting-period-table',
                    'method' => 'closePeriod',
                    'params' => ['periodId' => $row->id],
                    'title' => "Cerrar periodo '{$row->name}'?",
                    'description' => "Cerrar este periodo bloqueará todos sus asientos contables. No podrá registrar nuevas operaciones en este periodo. Asegúrese de haber creado el siguiente periodo antes de cerrar este.",
                    'confirmButtonText' => 'Cerrar Periodo',
                    'confirmButtonClass' => 'bg-amber-600 text-white hover:bg-amber-500',
                ])
                ->tooltip('Cerrar periodo');
        }

        return $actions;
    }

    public function header(): array
    {
        return [
            Button::add('new-period')
                ->slot('+ Nuevo Periodo')
                ->class('bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-md font-medium text-sm')
                ->dispatch('create-accounting-period', []),
        ];
    }

    #[On('closePeriod')]
    public function closePeriod(int $periodId): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);

        $period = AccountingPeriod::find($periodId);

        if (!$period) {
            $this->dispatch('toast', message: 'Periodo no encontrado.', type: 'error');
            return;
        }

        if ($period->status === AccountingPeriodStatus::Closed) {
            $this->dispatch('toast', message: 'El periodo ya está cerrado.', type: 'warning');
            return;
        }

        $period->update([
            'status'    => AccountingPeriodStatus::Closed->value,
            'closed_at' => now(),
            'closed_by' => auth()->id(),
        ]);

        $this->dispatch('toast', message: "Periodo '{$period->name}' cerrado correctamente.", type: 'success');
    }
}
