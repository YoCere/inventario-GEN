<?php

namespace App\Livewire\AccountingPeriods;

use App\Enums\AccountingPeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
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
            ->add('duration_days', fn (AccountingPeriod $model) => $model->start_date->diffInDays($model->end_date) + 1 . ' días')
            ->add('end_date_display', function (AccountingPeriod $model) {
                $formatted = $model->end_date->format('d/m/Y');
                if ($model->planned_end_date) {
                    $planned = $model->planned_end_date->format('d/m/Y');
                    return $formatted
                        . ' <span title="Planificado hasta ' . $planned . '" '
                        . 'class="inline-flex items-center ml-1 px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700 cursor-help">'
                        . '⚠️ ant.</span>';
                }
                return $formatted;
            })
            ->add('status_badge', function (AccountingPeriod $model) {
                if ($model->status === AccountingPeriodStatus::Open) {
                    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">Abierto</span>';
                }
                if ($model->wasClosedEarly()) {
                    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700">Cerrado (ant.)</span>';
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

            Column::make('Fin (real)', 'end_date_display', 'end_date')
                ->sortable(),

            Column::make('Duración', 'duration_days'),

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
            $entryCount  = $row->journal_entries_count ?? 0;
            $today       = Carbon::today();
            $isEarlyClose = $row->end_date->gt($today);

            $description = "Este periodo tiene {$entryCount} asiento(s) contable(s). "
                . "Al cerrarlo quedarán bloqueados y no podrá registrar nuevas operaciones en él.";

            if ($isEarlyClose) {
                $planned     = $row->end_date->format('d/m/Y');
                $todayStr    = $today->format('d/m/Y');
                $description .= "\n\n⚠️ CIERRE ANTICIPADO: Este periodo estaba planificado hasta el {$planned}, "
                    . "pero lo está cerrando hoy {$todayStr}. "
                    . "La fecha de fin se ajustará automáticamente a hoy, "
                    . "liberando las fechas posteriores para un nuevo periodo.";
            } else {
                $description .= " Asegúrese de haber creado el siguiente periodo antes de cerrar este.";
            }

            $actions[] = Button::add('close')
                ->slot('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>')
                ->class('bg-amber-500 hover:bg-amber-600 text-white p-2 rounded-md flex items-center justify-center')
                ->dispatch('open-delete-modal', [
                    'component'          => 'accounting-periods.accounting-period-table',
                    'method'             => 'closePeriod',
                    'params'             => ['periodId' => $row->id],
                    'title'              => "Cerrar periodo \"{$row->name}\"?",
                    'description'        => $description,
                    'confirmButtonText'  => $isEarlyClose ? '⚠️ Cerrar Anticipadamente' : 'Cerrar Periodo',
                    'confirmButtonClass' => 'bg-amber-600 text-white hover:bg-amber-500',
                ])
                ->tooltip($isEarlyClose ? 'Cerrar anticipadamente' : 'Cerrar periodo');
        }

        return $actions;
    }

    public function header(): array
    {
        $lastPeriod    = AccountingPeriod::orderByDesc('end_date')->first();
        $suggestedType  = Setting::get('default_accounting_period_type', 'monthly');

        // Si el último periodo fue cerrado anticipadamente, el nuevo debe
        // empezar desde el día siguiente al cierre real (end_date ya fue
        // truncado en closePeriod), no desde la fecha planificada original.
        $suggestedStart = $lastPeriod
            ? $lastPeriod->end_date->addDay()->format('Y-m-d')
            : now()->startOfMonth()->format('Y-m-d');

        return [
            Button::add('new-period')
                ->slot('+ Nuevo Periodo')
                ->class('bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-md font-medium text-sm')
                ->dispatch('create-accounting-period', [
                    'suggestedType'  => $suggestedType,
                    'suggestedStart' => $suggestedStart,
                ]),
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

        $today   = Carbon::today();
        $updates = [
            'status'    => AccountingPeriodStatus::Closed->value,
            'closed_at' => now(),
            'closed_by' => auth()->id(),
        ];

        // Cierre anticipado: el periodo se cierra antes de su fecha fin planificada.
        // Truncamos end_date al día real de cierre y guardamos la fecha planificada
        // en planned_end_date para auditoría. Esto libera las fechas posteriores
        // para que puedan pertenecer a un nuevo periodo sin conflicto de overlap.
        if ($period->end_date->gt($today)) {
            $updates['planned_end_date'] = $period->end_date->format('Y-m-d');
            $updates['end_date']         = $today->format('Y-m-d');
        }

        $period->update($updates);
        $period->refresh(); // end_date puede haber cambiado (cierre anticipado)

        $suffixClose = isset($updates['planned_end_date'])
            ? " Cerrado anticipadamente — fechas posteriores al {$today->format('d/m/Y')} liberadas."
            : '';

        // Auto-creación del siguiente periodo si está habilitada
        $suffixNext = '';
        if (Setting::get('auto_create_next_period', '1') === '1') {
            try {
                $next        = AccountingPeriod::autoCreateNext($period);
                $suffixNext  = " Auto-creado \"{$next->name}\" ({$next->start_date->format('d/m/Y')} → {$next->end_date->format('d/m/Y')}).";
            } catch (\Throwable $e) {
                Log::error('Error al auto-crear siguiente periodo: ' . $e->getMessage());
                $suffixNext = ' ⚠️ No se pudo crear el siguiente periodo automáticamente.';
            }
        }

        $this->dispatch('pg:eventRefresh-accounting-period-table');
        $this->dispatch('toast', message: "Periodo '{$period->name}' cerrado.{$suffixClose}{$suffixNext}", type: 'success');
    }
}
