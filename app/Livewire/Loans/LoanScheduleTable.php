<?php

namespace App\Livewire\Loans;

use App\Enums\InstallmentStatus;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Services\Accounting\LoanService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;
use PowerComponents\LivewirePowerGrid\Button;
use PowerComponents\LivewirePowerGrid\Column;
use PowerComponents\LivewirePowerGrid\PowerGridFields;
use PowerComponents\LivewirePowerGrid\Facades\PowerGrid;
use PowerComponents\LivewirePowerGrid\PowerGridComponent;

final class LoanScheduleTable extends PowerGridComponent
{
    public string $tableName = 'loan-schedule-table';
    public string $sortField = 'number';
    public string $sortDirection = 'asc';

    public int $loan;

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
                ->showPerPage(perPage: 25, perPageValues: [10, 25, 50, 100])
                ->showRecordCount(),
        ];
    }

    public function datasource(): Builder
    {
        return LoanInstallment::where('loan_id', $this->loan);
    }

    public function fields(): PowerGridFields
    {
        return PowerGrid::fields()
            ->add('id')
            ->add('number')
            ->add('due_date', fn (LoanInstallment $model) => $model->due_date->format('d/m/Y'))
            ->add('payment_fmt', fn (LoanInstallment $model) => format_money($model->payment_amount))
            ->add('interest_fmt', fn (LoanInstallment $model) => format_money($model->interest_amount))
            ->add('principal_fmt', fn (LoanInstallment $model) => format_money($model->principal_amount))
            ->add('balance_fmt', fn (LoanInstallment $model) => format_money($model->balance_after))
            ->add('status_badge', function (LoanInstallment $model) {
                $colors = [
                    InstallmentStatus::Pending->value => 'bg-amber-100 text-amber-800',
                    InstallmentStatus::Paid->value    => 'bg-green-100 text-green-800',
                ];
                $color = $colors[$model->status->value] ?? 'bg-gray-100 text-gray-800';
                $label = $model->status->label();
                return "<span class='inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {$color}'>{$label}</span>";
            });
    }

    public function columns(): array
    {
        return [
            Column::action('Acción'),

            Column::make('N°', 'number')
                ->sortable(),

            Column::make('Vencimiento', 'due_date')
                ->sortable(),

            Column::make('Cuota (Bs)', 'payment_fmt'),

            Column::make('Interés (Bs)', 'interest_fmt'),

            Column::make('Capital (Bs)', 'principal_fmt'),

            Column::make('Saldo (Bs)', 'balance_fmt'),

            Column::make('Estado', 'status_badge'),
        ];
    }

    public function filters(): array
    {
        return [];
    }

    public function actions(LoanInstallment $row): array
    {
        $actions = [];

        if ($row->status->value === InstallmentStatus::Pending->value) {
            $actions[] = Button::add('pay')
                ->slot('<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>')
                ->class('bg-green-500 hover:bg-green-600 text-white p-2 rounded-md flex items-center justify-center')
                ->dispatch('pay-installment', ['installmentId' => $row->id])
                ->tooltip('Registrar pago');
        }

        return $actions;
    }

    #[On('pay-installment')]
    public function payInstallment(int $installmentId): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $installment = LoanInstallment::findOrFail($installmentId);
        app(LoanService::class)->registerPayment($installment, now()->toDateString(), null, auth()->id());
        session()->flash('saved', 'Pago registrado.');
        $this->dispatch('pg:eventRefresh-' . $this->tableName);
    }

    public function payoffLoan(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        app(LoanService::class)->payoff(Loan::findOrFail($this->loan), now()->toDateString(), null, auth()->id());
        session()->flash('saved', 'Préstamo cancelado.');
        $this->dispatch('pg:eventRefresh-' . $this->tableName);
    }
}
