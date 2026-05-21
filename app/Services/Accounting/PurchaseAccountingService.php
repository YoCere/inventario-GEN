<?php

namespace App\Services\Accounting;

use App\Models\Purchase;
use App\Models\Setting;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\AccountingPeriod;
use App\Enums\JournalEntryStatus;
use Illuminate\Support\Carbon;
use RuntimeException;

class PurchaseAccountingService
{
    public function __construct(
        protected JournalEntryService $journalEntryService
    ) {
    }

    public function postPaidPurchase(Purchase $purchase, int $userId): ?JournalEntry
    {
        $exists = JournalEntry::query()
            ->where('source_type', Purchase::class)
            ->where('source_id', $purchase->id)
            ->where('status', JournalEntryStatus::Posted)
            ->whereNull('reversed_entry_id')
            ->exists();

        if ($exists) {
            return null;
        }

        $entryDate = Carbon::parse($purchase->purchase_date)->toDateString();
        $period = $this->resolveOpenPeriod($entryDate);

        $inventoryAccount = $this->findPostingAccount(Setting::get('accounting_inventory_code', '1.1.04'));
        $cashAccount = $this->findPostingAccount(Setting::get('accounting_purchase_cash_code', '1.1.01'));

        $total = (int) $purchase->total;

        $lines = [
            [
                'chart_of_account_id' => $inventoryAccount->id,
                'description' => 'Ingreso de inventario por compra ' . $purchase->invoice_number,
                'debit_amount' => $total,
                'credit_amount' => 0,
                'reference' => $purchase->invoice_number,
            ],
            [
                'chart_of_account_id' => $cashAccount->id,
                'description' => 'Salida de caja por compra ' . $purchase->invoice_number,
                'debit_amount' => 0,
                'credit_amount' => $total,
                'reference' => $purchase->invoice_number,
            ],
        ];

        return $this->journalEntryService->createPostedEntry([
            'entry_date' => $entryDate,
            'accounting_period_id' => $period->id,
            'description' => 'Asiento automático de compra ' . $purchase->invoice_number,
            'source_type' => Purchase::class,
            'source_id' => $purchase->id,
            'created_by' => $userId,
            'posted_by' => $userId,
        ], $lines);
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
