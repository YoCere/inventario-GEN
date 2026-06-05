<?php

namespace App\Livewire\Loans;

use Livewire\Component;
use Livewire\Attributes\On;
use App\Models\ChartOfAccount;
use App\Services\Accounting\LoanService;

class LoanForm extends Component
{
    public string $mode = 'new'; // 'new' | 'opening'

    public string $lender = '';
    public string $code = '';
    public float $principal = 0;
    public float $annual_rate_pct = 0;
    public int $term_months = 12;
    public string $start_date = '';
    public int $payment_day = 5;
    public string $liability_account_code = '2.2.01';
    public string $interest_account_code = '6.3';
    public string $payment_account_code = '1.1.02';
    public string $as_of_date = '';

    public function rules(): array
    {
        return [
            'mode'                    => ['required', 'in:new,opening'],
            'lender'                  => ['required', 'string', 'max:255'],
            'code'                    => ['required', 'string', 'unique:loans,code'],
            'principal'               => ['required', 'numeric', 'min:0.01'],
            'annual_rate_pct'         => ['required', 'numeric', 'min:0'],
            'term_months'             => ['required', 'integer', 'min:1'],
            'start_date'              => ['required', 'date'],
            'payment_day'             => ['required', 'integer', 'between:1,28'],
            'liability_account_code'  => ['required', 'exists:chart_of_accounts,code'],
            'interest_account_code'   => ['required', 'exists:chart_of_accounts,code'],
            'payment_account_code'    => ['required', 'exists:chart_of_accounts,code'],
            'as_of_date'              => ['required_if:mode,opening', 'nullable', 'date'],
        ];
    }

    public function getAccountOptionsProperty(): array
    {
        return ChartOfAccount::where('allows_posting', true)
            ->orderBy('code')
            ->get()
            ->map(fn ($a) => ['value' => $a->code, 'label' => $a->code . ' - ' . $a->name])
            ->toArray();
    }

    public function render()
    {
        return view('livewire.loans.loan-form', [
            'accountOptions' => $this->accountOptions,
        ]);
    }

    #[On('create-loan')]
    public function create(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $this->reset([
            'lender', 'code', 'principal', 'annual_rate_pct', 'term_months',
            'start_date', 'payment_day', 'as_of_date',
        ]);
        $this->mode = 'new';
        $this->liability_account_code = '2.2.01';
        $this->interest_account_code = '6.3';
        $this->payment_account_code = '1.1.02';
        $this->term_months = 12;
        $this->payment_day = 5;
        $this->principal = 0;
        $this->annual_rate_pct = 0;
        $this->dispatch('open-modal', name: 'loan-form-modal');
    }

    public function save(LoanService $service): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $this->validate();

        $data = [
            'lender'                  => $this->lender,
            'code'                    => $this->code,
            'principal'               => (int) round($this->principal * 100),
            'annual_rate_pct'         => $this->annual_rate_pct,
            'term_months'             => $this->term_months,
            'start_date'              => $this->start_date,
            'payment_day'             => $this->payment_day,
            'liability_account_code'  => $this->liability_account_code,
            'interest_account_code'   => $this->interest_account_code,
            'payment_account_code'    => $this->payment_account_code,
        ];

        if ($this->mode === 'new') {
            $service->registerNew($data, auth()->id());
        } else {
            $service->registerOpening($data, $this->as_of_date, auth()->id());
        }

        $this->dispatch('close-modal', name: 'loan-form-modal');
        $this->dispatch('pg:eventRefresh-loan-table');
        $this->dispatch('toast', message: 'Préstamo registrado correctamente.', type: 'success');
    }
}
