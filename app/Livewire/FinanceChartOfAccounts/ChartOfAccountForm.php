<?php

namespace App\Livewire\FinanceChartOfAccounts;

use Livewire\Component;
use Livewire\Attributes\On;
use App\Enums\AccountType;
use App\Enums\AccountNormalBalance;
use App\Models\ChartOfAccount;
use App\Services\Accounting\ChartOfAccountService;
use Illuminate\Validation\Rule;

class ChartOfAccountForm extends Component
{
    // ── Modal / editing state ───────────────────────────────────────────────
    public bool $showModal      = false;
    public bool $isEditing      = false;
    public bool $lockStructural = false;

    /** @var ChartOfAccount|null */
    public ?ChartOfAccount $account = null;

    // ── Form fields ─────────────────────────────────────────────────────────
    public string  $code           = '';
    public string  $name           = '';
    public ?int    $parent_id      = null;
    public ?string $account_type   = null;
    public string  $normal_balance = '';
    public bool    $allows_posting = true;
    public string  $description    = '';
    public bool    $is_active      = true;

    // ── Select options ──────────────────────────────────────────────────────
    public array $parentOptions      = [];
    public array $accountTypeOptions = [];
    public array $normalBalanceOptions = [];

    // ───────────────────────────────────────────────────────────────────────
    public function mount(): void
    {
        $this->loadOptions();
    }

    public function loadOptions(): void
    {
        $this->parentOptions = ChartOfAccount::where('allows_posting', false)
            ->orderBy('code')
            ->get()
            ->map(fn (ChartOfAccount $a) => [
                'value' => $a->id,
                'label' => $a->code . ' - ' . $a->name,
            ])
            ->toArray();

        $this->accountTypeOptions = array_map(
            fn (AccountType $c) => ['value' => $c->value, 'label' => $c->label()],
            AccountType::cases()
        );

        $this->normalBalanceOptions = array_map(
            fn (AccountNormalBalance $c) => ['value' => $c->value, 'label' => $c->label()],
            AccountNormalBalance::cases()
        );
    }

    // ───────────────────────────────────────────────────────────────────────
    #[On('create-chart-account')]
    public function create(): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);

        $this->reset([
            'account', 'code', 'name', 'parent_id', 'account_type',
            'normal_balance', 'description',
        ]);

        $this->isEditing      = false;
        $this->lockStructural = false;
        $this->allows_posting = true;
        $this->is_active      = true;
        $this->account_type   = null;

        $this->loadOptions();
        $this->showModal = true;
    }

    // ───────────────────────────────────────────────────────────────────────
    #[On('edit-chart-account')]
    public function edit(int|string $account): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);

        $acct = ChartOfAccount::findOrFail($account);

        $this->account        = $acct;
        $this->code           = $acct->code;
        $this->name           = $acct->name;
        $this->parent_id      = $acct->parent_id;
        $this->account_type   = $acct->account_type->value;
        $this->normal_balance = $acct->normal_balance->value;
        $this->allows_posting = $acct->allows_posting;
        $this->description    = $acct->description ?? '';
        $this->is_active      = $acct->is_active;

        $this->isEditing      = true;
        $this->lockStructural = app(ChartOfAccountService::class)->hasMovements($acct);

        $this->loadOptions();
        $this->showModal = true;
    }

    // ───────────────────────────────────────────────────────────────────────
    /** Auto-set normal_balance default when account_type changes. */
    public function updatedAccountType(string $value): void
    {
        if (!empty($this->normal_balance)) {
            return;
        }

        $debitTypes = ['asset', 'expense', 'cost'];
        $this->normal_balance = in_array($value, $debitTypes) ? 'debit' : 'credit';
    }

    // ───────────────────────────────────────────────────────────────────────
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'regex:/^\d+(\.\d+)*$/',
                Rule::unique('chart_of_accounts', 'code')->ignore($this->account?->id),
            ],
            'name'           => ['required', 'string', 'max:255'],
            'parent_id'      => ['nullable', 'exists:chart_of_accounts,id'],
            'account_type'   => ['required', Rule::in(array_column(AccountType::cases(), 'value'))],
            'normal_balance' => ['required', Rule::in(array_column(AccountNormalBalance::cases(), 'value'))],
            'allows_posting' => ['boolean'],
            'description'    => ['nullable', 'string'],
            'is_active'      => ['boolean'],
        ];
    }

    // ───────────────────────────────────────────────────────────────────────
    public function save(): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);

        $data = $this->validate();

        try {
            $service = app(ChartOfAccountService::class);

            if ($this->isEditing) {
                $service->update($this->account, $data);
            } else {
                $service->create($data);
            }

            $this->dispatch('toast', message: 'Cuenta guardada correctamente.', type: 'success');
            $this->dispatch('chart-account-saved');
            $this->showModal = false;
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        }
    }

    // ───────────────────────────────────────────────────────────────────────
    public function render()
    {
        return view('livewire.finance-chart-of-accounts.chart-of-account-form');
    }
}
