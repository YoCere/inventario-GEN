<?php

namespace App\Livewire\FinanceJournalEntries;

use App\Models\JournalEntry;
use App\Enums\JournalEntryStatus;
use Illuminate\Database\Eloquent\Builder;
use PowerComponents\LivewirePowerGrid\Button;
use PowerComponents\LivewirePowerGrid\Column;
use PowerComponents\LivewirePowerGrid\Facades\Filter;
use PowerComponents\LivewirePowerGrid\PowerGridFields;
use PowerComponents\LivewirePowerGrid\Facades\PowerGrid;
use PowerComponents\LivewirePowerGrid\Traits\WithExport;
use PowerComponents\LivewirePowerGrid\PowerGridComponent;
use PowerComponents\LivewirePowerGrid\Components\SetUp\Exportable;

final class JournalEntryTable extends PowerGridComponent
{
    use WithExport;

    public string $tableName = 'journal-entry-table';
    public string $sortField = 'entry_date';
    public string $sortDirection = 'desc';

    public function boot(): void
    {
        config(['livewire-powergrid.filter' => 'outside']);
    }

    public function setUp(): array
    {
        return [
            PowerGrid::exportable('libro_diario_' . now()->format('Y_m_d'))
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
        return JournalEntry::query()
            ->with(['creator', 'accountingPeriod'])
            ->withSum('lines as debit_total', 'debit_amount')
            ->withSum('lines as credit_total', 'credit_amount');
    }

    public function fields(): PowerGridFields
    {
        return PowerGrid::fields()
            ->add('id')
            ->add('entry_number')
            ->add('entry_date_formatted', fn (JournalEntry $model) => $model->entry_date->format('d/m/Y'))
            ->add('description')
            ->add('status_label', fn (JournalEntry $model) => $model->status->label())
            ->add('period_name', fn (JournalEntry $model) => $model->accountingPeriod?->name ?? '-')
            ->add('creator_name', fn (JournalEntry $model) => $model->creator?->name ?? '-')
            ->add('debit_total', fn (JournalEntry $model) => format_money((int) ($model->debit_total ?? 0)))
            ->add('credit_total', fn (JournalEntry $model) => format_money((int) ($model->credit_total ?? 0)));
    }

    public function columns(): array
    {
        return [
            Column::action('Accion'),

            Column::make('Asiento', 'entry_number')
                ->sortable()
                ->searchable(),

            Column::make('Fecha', 'entry_date_formatted', 'entry_date')
                ->sortable(),

            Column::make('Descripcion', 'description')
                ->sortable()
                ->searchable(),

            Column::make('Periodo', 'period_name')
                ->sortable(),

            Column::make('Estado', 'status_label', 'status')
                ->sortable(),

            Column::make('Debe', 'debit_total')
                ->headerAttribute('text-right')
                ->bodyAttribute('text-right'),

            Column::make('Haber', 'credit_total')
                ->headerAttribute('text-right')
                ->bodyAttribute('text-right'),

            Column::make('Creado por', 'creator_name')
                ->sortable(),

        ];
    }

    public function filters(): array
    {
        return [
            Filter::datepicker('entry_date_formatted', 'entry_date')
                ->params([
                    'enableTime' => false,
                    'dateFormat' => 'Y-m-d',
                    'altInput' => true,
                    'altFormat' => 'd/m/Y',
                ]),

            Filter::select('status', 'status')
                ->dataSource(collect(JournalEntryStatus::cases())->map(fn ($status) => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ])->toArray())
                ->optionLabel('label')
                ->optionValue('value'),
        ];
    }

    public function actions(JournalEntry $row): array
    {
        return [
            Button::add('view')
                ->slot('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>')
                ->class('bg-blue-500 hover:bg-blue-600 text-white p-2 rounded-md flex items-center justify-center')
                ->dispatch('view-journal-entry', ['entry' => $row->id])
                ->tooltip('Ver Asiento'),
        ];
    }
}
