<?php

namespace App\Services\Accounting;

use App\Enums\VoucherType;
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

    /**
     * Contabiliza una compra según su método de pago.
     * - cash: Débito Inventario / Crédito Caja
     * - credit/cualquier otro: Débito Inventario / Crédito Cuentas por Pagar
     */
    public function postPurchase(Purchase $purchase, int $userId): ?JournalEntry
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
        $total = (int) $purchase->total;

        // Seleccionar cuenta de contrapartida según método de pago
        $paymentMethod = $purchase->payment_method ?? 'cash';
        if ($paymentMethod === 'cash') {
            $creditAccount = $this->findPostingAccount(Setting::get('accounting_purchase_cash_code', '1.1.01'));
            $creditDescription = 'Salida de caja por compra ' . $purchase->invoice_number;
        } else {
            // credit, transfer, o cualquier otro → cuentas por pagar
            $creditAccount = $this->findPostingAccount(Setting::get('accounting_purchase_payable_code', '2.1.01'));
            $creditDescription = 'Cuenta por pagar por compra ' . $purchase->invoice_number;
        }

        $lines = [
            [
                'chart_of_account_id' => $inventoryAccount->id,
                'description' => 'Ingreso de inventario por compra ' . $purchase->invoice_number,
                'debit_amount' => $total,
                'credit_amount' => 0,
                'reference' => $purchase->invoice_number,
            ],
            [
                'chart_of_account_id' => $creditAccount->id,
                'description' => $creditDescription,
                'debit_amount' => 0,
                'credit_amount' => $total,
                'reference' => $purchase->invoice_number,
            ],
        ];

        return $this->journalEntryService->createPostedEntry([
            'entry_date'           => $entryDate,
            'accounting_period_id' => $period->id,
            'description'          => 'Asiento automático de compra ' . $purchase->invoice_number,
            'source_type'          => Purchase::class,
            'source_id'            => $purchase->id,
            'voucher_type'         => VoucherType::Egreso->value,
            'created_by'           => $userId,
            'posted_by'            => $userId,
        ], $lines);
    }

    /**
     * Alias backward-compatible.
     */
    public function postPaidPurchase(Purchase $purchase, int $userId): ?JournalEntry
    {
        return $this->postPurchase($purchase, $userId);
    }

    /**
     * Reverse the GL journal entry for a purchase (used when a PAID purchase is cancelled).
     * Returns the reversal entry, or null if no posted entry exists (idempotent).
     */
    public function reversePurchaseEntry(Purchase $purchase, int $userId, ?string $reason = null): ?JournalEntry
    {
        $original = $this->journalEntryService->findPostedSourceEntry(Purchase::class, $purchase->id);

        if (!$original) {
            return null;
        }

        $description = 'Reverso contable por cancelación de compra ' . $purchase->invoice_number;
        if ($reason) {
            $description .= ' | Motivo: ' . $reason;
        }

        return $this->journalEntryService->reverseEntry($original, $userId, $description);
    }

    protected function resolveOpenPeriod(string $entryDate): AccountingPeriod
    {
        return AccountingPeriod::resolveOpenForDate($entryDate);
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
