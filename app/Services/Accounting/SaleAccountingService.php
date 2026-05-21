<?php

namespace App\Services\Accounting;

use App\Models\Sale;
use App\Models\Setting;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\AccountingPeriod;
use App\Enums\JournalEntryStatus;
use Illuminate\Support\Carbon;
use RuntimeException;

class SaleAccountingService
{
    public function __construct(
        protected JournalEntryService $journalEntryService
    ) {
    }

    public function postCompletedSale(Sale $sale, int $userId): ?JournalEntry
    {
        $exists = JournalEntry::query()
            ->where('source_type', Sale::class)
            ->where('source_id', $sale->id)
            ->where('status', JournalEntryStatus::Posted)
            ->whereNull('reversed_entry_id')
            ->exists();

        if ($exists) {
            return null;
        }

        $sale->loadMissing('items');

        $entryDate = Carbon::parse($sale->sale_date)->toDateString();
        $period = $this->resolveOpenPeriod($entryDate);

        $debitAccountCode = match ($sale->payment_method?->value ?? (string) $sale->payment_method) {
            'cash' => Setting::get('accounting_sale_cash_code', '1.1.01'),
            'transfer' => Setting::get('accounting_sale_transfer_code', '1.1.02'),
            default => Setting::get('accounting_sale_other_code', '1.1.03'),
        };

        $debitAccount = $this->findPostingAccount($debitAccountCode);
        $salesIncomeAccount = $this->findPostingAccount(Setting::get('accounting_sale_income_code', '4.1'));
        $costOfSalesAccount = $this->findPostingAccount(Setting::get('accounting_cogs_code', '5.1'));
        $inventoryAccount = $this->findPostingAccount(Setting::get('accounting_inventory_code', '1.1.04'));

        $cogs = (int) $sale->items->sum(fn ($item) => (int) $item->quantity * (int) $item->cost_price);

        $lines = [
            [
                'chart_of_account_id' => $debitAccount->id,
                'description' => 'Cobro de venta ' . $sale->invoice_number,
                'debit_amount' => (int) $sale->total,
                'credit_amount' => 0,
                'reference' => $sale->invoice_number,
            ],
            [
                'chart_of_account_id' => $salesIncomeAccount->id,
                'description' => 'Ingreso por venta ' . $sale->invoice_number,
                'debit_amount' => 0,
                'credit_amount' => (int) $sale->total,
                'reference' => $sale->invoice_number,
            ],
        ];

        if ($cogs > 0) {
            $lines[] = [
                'chart_of_account_id' => $costOfSalesAccount->id,
                'description' => 'Costo de venta ' . $sale->invoice_number,
                'debit_amount' => $cogs,
                'credit_amount' => 0,
                'reference' => $sale->invoice_number,
            ];
            $lines[] = [
                'chart_of_account_id' => $inventoryAccount->id,
                'description' => 'Salida de inventario ' . $sale->invoice_number,
                'debit_amount' => 0,
                'credit_amount' => $cogs,
                'reference' => $sale->invoice_number,
            ];
        }

        return $this->journalEntryService->createPostedEntry([
            'entry_date' => $entryDate,
            'accounting_period_id' => $period->id,
            'description' => 'Asiento automático de venta ' . $sale->invoice_number,
            'source_type' => Sale::class,
            'source_id' => $sale->id,
            'created_by' => $userId,
            'posted_by' => $userId,
        ], $lines);
    }

    public function reverseSaleEntry(Sale $sale, int $userId, ?string $reason = null): ?JournalEntry
    {
        $original = $this->journalEntryService->findPostedSourceEntry(Sale::class, $sale->id);

        if (!$original) {
            return null;
        }

        $description = 'Reverso contable por cancelación de venta ' . $sale->invoice_number;
        if ($reason) {
            $description .= ' | Motivo: ' . $reason;
        }

        return $this->journalEntryService->reverseEntry($original, $userId, $description);
    }

    protected function resolveOpenPeriod(string $entryDate): AccountingPeriod
    {
        $period = AccountingPeriod::query()
            ->whereDate('start_date', '<=', $entryDate)
            ->whereDate('end_date', '>=', $entryDate)
            ->where('status', 'open')
            ->orderBy('start_date')
            ->first();

        if (!$period) {
            throw new RuntimeException("No existe un periodo contable abierto para la fecha {$entryDate}.");
        }

        return $period;
    }

    protected function findPostingAccount(string $code): ChartOfAccount
    {
        $account = ChartOfAccount::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->where('allows_posting', true)
            ->first();

        if (!$account) {
            throw new RuntimeException("No existe cuenta contable activa/imputable con código {$code}.");
        }

        return $account;
    }
}
