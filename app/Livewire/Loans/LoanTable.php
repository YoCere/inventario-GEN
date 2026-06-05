<?php

namespace App\Livewire\Loans;

use App\Enums\LoanStatus;
use App\Models\Loan;
use Illuminate\Database\Eloquent\Builder;
use PowerComponents\LivewirePowerGrid\Button;
use PowerComponents\LivewirePowerGrid\Column;
use PowerComponents\LivewirePowerGrid\PowerGridFields;
use PowerComponents\LivewirePowerGrid\Facades\PowerGrid;
use PowerComponents\LivewirePowerGrid\Traits\WithExport;
use PowerComponents\LivewirePowerGrid\PowerGridComponent;
use PowerComponents\LivewirePowerGrid\Components\SetUp\Exportable;

final class LoanTable extends PowerGridComponent
{
    use WithExport;

    public string $tableName = 'loan-table';
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
            PowerGrid::exportable('prestamos_' . now()->format('Y_m_d'))
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
        return Loan::query();
    }

    public function fields(): PowerGridFields
    {
        return PowerGrid::fields()
            ->add('id')
            ->add('lender')
            ->add('code')
            ->add('principal_fmt', fn (Loan $model) => format_money($model->principal))
            ->add('outstanding_fmt', fn (Loan $model) => format_money($model->outstanding_balance))
            ->add('annual_rate_pct')
            ->add('term_months')
            ->add('status_badge', function (Loan $model) {
                $colors = [
                    LoanStatus::Active->value  => 'bg-green-100 text-green-800',
                    LoanStatus::PaidOff->value => 'bg-blue-100 text-blue-800',
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

            Column::make('Acreedor', 'lender')
                ->sortable()
                ->searchable(),

            Column::make('Capital (Bs)', 'principal_fmt'),

            Column::make('Saldo (Bs)', 'outstanding_fmt'),

            Column::make('Tasa anual %', 'annual_rate_pct')
                ->sortable(),

            Column::make('Plazo (meses)', 'term_months')
                ->sortable(),

            Column::make('Estado', 'status_badge'),
        ];
    }

    public function filters(): array
    {
        return [];
    }

    public function actions(Loan $row): array
    {
        return [
            Button::add('schedule')
                ->slot('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" /></svg>')
                ->class('bg-blue-500 hover:bg-blue-600 text-white p-2 rounded-md flex items-center justify-center')
                ->route('finance.loans.schedule', ['loan' => $row->id])
                ->tooltip('Ver cronograma'),
        ];
    }
}
