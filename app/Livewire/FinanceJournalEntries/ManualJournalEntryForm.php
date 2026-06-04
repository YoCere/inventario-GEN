<?php

namespace App\Livewire\FinanceJournalEntries;

use App\Enums\VoucherType;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ManualJournalEntryForm extends Component
{
    public string $entry_date = '';
    public string $voucher_type = 'ingreso';
    public ?string $description = null;
    public array $lines = [];
    public array $accountOptions = [];

    public function mount(): void
    {
        $this->entry_date = now()->format('Y-m-d');

        $this->lines = [
            ['chart_of_account_id' => null, 'side' => 'debit',  'amount' => null, 'description' => null],
            ['chart_of_account_id' => null, 'side' => 'credit', 'amount' => null, 'description' => null],
        ];

        $this->accountOptions = ChartOfAccount::where('is_active', true)
            ->where('allows_posting', true)
            ->orderBy('code')
            ->get()
            ->map(fn ($a) => ['value' => $a->id, 'label' => $a->code . ' - ' . $a->name])
            ->toArray();
    }

    public function addLine(): void
    {
        $this->lines[] = [
            'chart_of_account_id' => null,
            'side'                => 'debit',
            'amount'              => null,
            'description'         => null,
        ];
    }

    public function removeLine(int $index): void
    {
        if (count($this->lines) <= 2) {
            return;
        }
        array_splice($this->lines, $index, 1);
        $this->lines = array_values($this->lines);
    }

    public function totalDebit(): float
    {
        return collect($this->lines)
            ->where('side', 'debit')
            ->sum(fn ($l) => (float) ($l['amount'] ?? 0));
    }

    public function totalCredit(): float
    {
        return collect($this->lines)
            ->where('side', 'credit')
            ->sum(fn ($l) => (float) ($l['amount'] ?? 0));
    }

    public function isBalanced(): bool
    {
        $debit  = $this->totalDebit();
        $credit = $this->totalCredit();
        return $debit > 0 && $credit > 0 && abs($debit - $credit) < 0.001;
    }

    public function rules(): array
    {
        return [
            'entry_date'                       => ['required', 'date'],
            'voucher_type'                     => ['required', Rule::enum(VoucherType::class)],
            'description'                      => ['nullable', 'string'],
            'lines'                            => ['array', 'min:2'],
            'lines.*.chart_of_account_id'      => ['required', 'exists:chart_of_accounts,id'],
            'lines.*.side'                     => ['required', 'in:debit,credit'],
            'lines.*.amount'                   => ['required', 'numeric', 'min:0.01'],
            'lines.*.description'              => ['nullable', 'string'],
        ];
    }

    public function save(): mixed
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $this->validate();

        try {
            $period = AccountingPeriod::resolveOpenForDate($this->entry_date);

            $lines = collect($this->lines)->map(fn ($l) => [
                'chart_of_account_id' => (int) $l['chart_of_account_id'],
                'debit_amount'  => $l['side'] === 'debit'  ? (int) round(((float) $l['amount']) * 100) : 0,
                'credit_amount' => $l['side'] === 'credit' ? (int) round(((float) $l['amount']) * 100) : 0,
                'description'   => $l['description'] ?? null,
            ])->all();

            app(\App\Services\Accounting\JournalEntryService::class)->createPostedEntry([
                'entry_date'           => $this->entry_date,
                'accounting_period_id' => $period->id,
                'description'          => $this->description,
                'voucher_type'         => $this->voucher_type,
                'created_by'           => auth()->id(),
            ], $lines);

            // The toaster component reads session('success') on load.
            // Use that key so the flash survives the redirect.
            session()->flash('success', 'Asiento registrado correctamente.');

            return $this->redirect(route('finance.journal-entries.index'), navigate: true);
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        }

        return null;
    }

    public function render()
    {
        return view('livewire.finance-journal-entries.manual-journal-entry-form');
    }
}
