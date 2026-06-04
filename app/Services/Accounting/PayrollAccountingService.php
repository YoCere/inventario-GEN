<?php

namespace App\Services\Accounting;

use App\Enums\PayrollSheetStatus;
use App\Enums\VoucherType;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\PayrollSheet;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PayrollAccountingService
{
    public function __construct(
        protected JournalEntryService $journalEntryService
    ) {
    }

    public function postSheet(PayrollSheet $sheet, int $userId)
    {
        if ($sheet->status !== PayrollSheetStatus::DRAFT) {
            throw new RuntimeException('Solo se pueden contabilizar planillas en borrador.');
        }

        $sheet->loadMissing('items');
        if ($sheet->items->isEmpty()) {
            throw new RuntimeException('No se puede contabilizar una planilla sin items.');
        }

        $entryDate = Carbon::parse($sheet->payment_date)->toDateString();
        $period = $this->resolveOpenPeriod($entryDate);

        return DB::transaction(function () use ($sheet, $userId, $period, $entryDate) {
            $debitByArea = [
                'mod' => (int) $sheet->items->where('area', 'mod')->sum('total_employer_cost'),
                'moi' => (int) $sheet->items->where('area', 'moi')->sum('total_employer_cost'),
                'sales' => (int) $sheet->items->where('area', 'sales')->sum('total_employer_cost'),
                'admin' => (int) $sheet->items->where('area', 'admin')->sum('total_employer_cost'),
            ];

            $creditTotals = [
                'net_payable' => (int) $sheet->items->sum('net_payable'),
                'employer_contribution' => (int) $sheet->items->sum('employer_contribution'),
                'labor_contribution' => (int) $sheet->items->sum('labor_contribution'),
                'aguinaldo_provision' => (int) $sheet->items->sum('aguinaldo_provision'),
                'indemnization_provision' => (int) $sheet->items->sum('indemnization_provision'),
                'rc_iva' => (int) $sheet->items->sum('rc_iva'),
                'solidarity' => (int) $sheet->items->sum('solidarity_1') + (int) $sheet->items->sum('solidarity_2'),
                'other_discounts' => (int) $sheet->items->sum('other_discounts'),
            ];

            $lines = [];

            $areaAccountCodes = [
                'mod' => Setting::get('payroll_account_mod', '5.2'),
                'moi' => Setting::get('payroll_account_moi', '5.3'),
                'sales' => Setting::get('payroll_account_sales', '6.2'),
                'admin' => Setting::get('payroll_account_admin', '6.1'),
            ];

            foreach ($debitByArea as $area => $amount) {
                if ($amount <= 0) {
                    continue;
                }

                $account = $this->findPostingAccount($areaAccountCodes[$area]);
                $lines[] = [
                    'chart_of_account_id' => $account->id,
                    'description' => 'Gasto planilla area ' . strtoupper($area) . ' - ' . $sheet->sheet_number,
                    'debit_amount' => $amount,
                    'credit_amount' => 0,
                    'reference' => $sheet->sheet_number,
                ];
            }

            $creditAccounts = [
                'net_payable' => Setting::get('payroll_account_net_payable', '2.1.03'),
                'employer_contribution' => Setting::get('payroll_account_employer_contribution', '2.1.04'),
                'labor_contribution' => Setting::get('payroll_account_labor_contribution', '2.1.05'),
                'aguinaldo_provision' => Setting::get('payroll_account_aguinaldo_provision', '2.1.06'),
                'indemnization_provision' => Setting::get('payroll_account_indemnization_provision', '2.1.07'),
                'rc_iva' => Setting::get('payroll_account_rc_iva', '2.1.08'),
                'solidarity' => Setting::get('payroll_account_solidarity', '2.1.09'),
                'other_discounts' => Setting::get('payroll_account_other_discounts', '2.1.10'),
            ];

            foreach ($creditTotals as $key => $amount) {
                if ($amount <= 0) {
                    continue;
                }

                $account = $this->findPostingAccount($creditAccounts[$key]);
                $lines[] = [
                    'chart_of_account_id' => $account->id,
                    'description' => 'Retencion/obligacion planilla ' . $sheet->sheet_number . ' (' . $key . ')',
                    'debit_amount' => 0,
                    'credit_amount' => $amount,
                    'reference' => $sheet->sheet_number,
                ];
            }

            $entry = $this->journalEntryService->createPostedEntry([
                'entry_date'           => $entryDate,
                'accounting_period_id' => $period->id,
                'description'          => 'Asiento automatico de planilla ' . $sheet->sheet_number,
                'source_type'          => PayrollSheet::class,
                'source_id'            => $sheet->id,
                'voucher_type'         => VoucherType::Traspaso->value,
                'created_by'           => $userId,
                'posted_by'            => $userId,
            ], $lines);

            $sheet->update([
                'status' => PayrollSheetStatus::POSTED,
                'posted_at' => now(),
                'posted_by' => $userId,
                'accounting_period_id' => $period->id,
                'journal_entry_id' => $entry->id,
            ]);

            return $sheet->fresh(['items', 'journalEntry']);
        });
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

        if (! $account) {
            throw new RuntimeException("No existe cuenta contable activa/imputable con codigo {$code}.");
        }

        return $account;
    }
}

