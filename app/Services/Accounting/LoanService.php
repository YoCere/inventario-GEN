<?php

namespace App\Services\Accounting;

use App\Enums\InstallmentStatus;
use App\Enums\LoanStatus;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\Loan;
use App\Models\LoanInstallment;
use Illuminate\Support\Facades\DB;

class LoanService
{
    public function __construct(
        private JournalEntryService $journal,
        private AmortizationCalculator $calculator,
    ) {
    }

    public function registerNew(array $data, int $userId): Loan
    {
        return DB::transaction(function () use ($data, $userId) {
            $loan = Loan::create($data + [
                'status' => LoanStatus::Active->value,
                'is_opening' => false,
                'outstanding_balance' => (int) $data['principal'],
            ]);

            $this->generateInstallments($loan);

            $banco = ChartOfAccount::where('code', $loan->payment_account_code)->firstOrFail();
            $prestamo = ChartOfAccount::where('code', $loan->liability_account_code)->firstOrFail();
            $period = AccountingPeriod::resolveOpenForDate($loan->start_date->toDateString());

            $entry = $this->journal->createPostedEntry([
                'entry_date' => $loan->start_date->toDateString(),
                'accounting_period_id' => $period->id,
                'description' => "Desembolso préstamo {$loan->code} - {$loan->lender}",
                'entry_type' => 'normal',
                'voucher_type' => 'ingreso',
                'created_by' => $userId,
            ], [
                ['chart_of_account_id' => $banco->id, 'debit_amount' => (int) $loan->principal, 'credit_amount' => 0],
                ['chart_of_account_id' => $prestamo->id, 'debit_amount' => 0, 'credit_amount' => (int) $loan->principal],
            ]);

            $loan->update(['disbursement_entry_id' => $entry->id]);
            return $loan->fresh();
        });
    }

    public function registerOpening(array $data, string $asOfDate, int $userId): Loan
    {
        return DB::transaction(function () use ($data, $asOfDate) {
            $loan = Loan::create($data + [
                'status' => LoanStatus::Active->value,
                'is_opening' => true,
                'outstanding_balance' => (int) $data['principal'],
            ]);

            $this->generateInstallments($loan);

            $loan->installments()
                ->whereDate('due_date', '<', $asOfDate)
                ->update(['status' => InstallmentStatus::Paid->value]);

            $lastPaid = $loan->installments()->where('status', 'paid')->orderByDesc('number')->first();
            $outstanding = $lastPaid ? (int) $lastPaid->balance_after : (int) $loan->principal;
            $loan->update(['outstanding_balance' => $outstanding]);

            return $loan->fresh();
        });
    }

    public function registerPayment(LoanInstallment $installment, string $date, ?string $paymentAccountCode, int $userId): LoanInstallment
    {
        if ($installment->status === InstallmentStatus::Paid) {
            return $installment;
        }

        return DB::transaction(function () use ($installment, $date, $paymentAccountCode, $userId) {
            $loan = $installment->loan;
            $bancoCode = $paymentAccountCode ?: $loan->payment_account_code;

            $interes = ChartOfAccount::where('code', $loan->interest_account_code)->firstOrFail();
            $prestamo = ChartOfAccount::where('code', $loan->liability_account_code)->firstOrFail();
            $banco = ChartOfAccount::where('code', $bancoCode)->firstOrFail();
            $period = AccountingPeriod::resolveOpenForDate($date);

            $lines = [];
            if ((int) $installment->interest_amount > 0) {
                $lines[] = ['chart_of_account_id' => $interes->id, 'debit_amount' => (int) $installment->interest_amount, 'credit_amount' => 0];
            }
            $lines[] = ['chart_of_account_id' => $prestamo->id, 'debit_amount' => (int) $installment->principal_amount, 'credit_amount' => 0];
            $lines[] = ['chart_of_account_id' => $banco->id, 'debit_amount' => 0, 'credit_amount' => (int) $installment->payment_amount];

            $entry = $this->journal->createPostedEntry([
                'entry_date' => $date,
                'accounting_period_id' => $period->id,
                'description' => "Pago cuota {$installment->number} préstamo {$loan->code}",
                'entry_type' => 'normal',
                'voucher_type' => 'egreso',
                'created_by' => $userId,
            ], $lines);

            $installment->update([
                'status' => InstallmentStatus::Paid->value,
                'paid_date' => $date,
                'journal_entry_id' => $entry->id,
            ]);

            $newBalance = max((int) $loan->outstanding_balance - (int) $installment->principal_amount, 0);
            $loan->update([
                'outstanding_balance' => $newBalance,
                'status' => $newBalance <= 0 ? LoanStatus::PaidOff->value : LoanStatus::Active->value,
            ]);

            return $installment->fresh();
        });
    }

    public function payoff(Loan $loan, string $date, ?string $paymentAccountCode, int $userId): Loan
    {
        if ($loan->status === LoanStatus::PaidOff) {
            return $loan;
        }

        return DB::transaction(function () use ($loan, $date, $paymentAccountCode, $userId) {
            $bancoCode = $paymentAccountCode ?: $loan->payment_account_code;
            $prestamo = ChartOfAccount::where('code', $loan->liability_account_code)->firstOrFail();
            $banco = ChartOfAccount::where('code', $bancoCode)->firstOrFail();
            $period = AccountingPeriod::resolveOpenForDate($date);
            $balance = (int) $loan->outstanding_balance;

            $entry = $this->journal->createPostedEntry([
                'entry_date' => $date,
                'accounting_period_id' => $period->id,
                'description' => "Cancelación préstamo {$loan->code} - {$loan->lender}",
                'entry_type' => 'normal',
                'voucher_type' => 'egreso',
                'created_by' => $userId,
            ], [
                ['chart_of_account_id' => $prestamo->id, 'debit_amount' => $balance, 'credit_amount' => 0],
                ['chart_of_account_id' => $banco->id, 'debit_amount' => 0, 'credit_amount' => $balance],
            ]);

            $loan->installments()->where('status', 'pending')->update([
                'status' => InstallmentStatus::Paid->value,
                'paid_date' => $date,
                'journal_entry_id' => $entry->id,
            ]);

            $loan->update(['outstanding_balance' => 0, 'status' => LoanStatus::PaidOff->value]);

            return $loan->fresh();
        });
    }

    private function generateInstallments(Loan $loan): void
    {
        $rows = $this->calculator->schedule(
            (int) $loan->principal,
            (float) $loan->annual_rate_pct,
            (int) $loan->term_months,
            $loan->start_date->toDateString(),
            (int) $loan->payment_day,
        );

        foreach ($rows as $row) {
            $loan->installments()->create([
                'number' => $row['number'],
                'due_date' => $row['due_date'],
                'payment_amount' => $row['payment_amount'],
                'interest_amount' => $row['interest_amount'],
                'principal_amount' => $row['principal_amount'],
                'balance_after' => $row['balance_after'],
                'status' => InstallmentStatus::Pending->value,
            ]);
        }
    }
}
