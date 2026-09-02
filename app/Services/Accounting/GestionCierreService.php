<?php

namespace App\Services\Accounting;

use App\Enums\JournalEntryType;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\Setting;
use App\Services\FinancialStatementService;
use RuntimeException;

class GestionCierreService
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
        protected FinancialStatementService $statements,
    ) {}

    /** @return array{utilidad_antes_impuestos:int, iue:int, reserva_legal:int, ya_cerrada:bool, lines:array} */
    public function preview(int $year): array
    {
        $er = $this->statements->build("{$year}-01-01", "{$year}-12-31", withTaxes: true)['estado_resultados'];
        $iue = (int) $er['iue'];
        $reserva = (int) $er['reserva_legal'];

        return [
            'utilidad_antes_impuestos' => (int) $er['utilidad_antes_impuestos'],
            'iue' => $iue,
            'reserva_legal' => $reserva,
            'ya_cerrada' => $this->alreadyClosed($year),
            'lines' => $this->buildLines($iue, $reserva),
        ];
    }

    protected function alreadyClosed(int $year): bool
    {
        $period = AccountingPeriod::whereYear('start_date', $year)->orderBy('start_date')->first();
        if (! $period) {
            return false;
        }

        $entry = $this->journalEntryService->findPostedSourceEntry(AccountingPeriod::class, $period->id);

        return $entry !== null && $entry->entry_type === JournalEntryType::Cierre;
    }

    /** @return array<int, array{chart_of_account_id:int,debit_amount:int,credit_amount:int,description:string}> */
    protected function buildLines(int $iue, int $reserva): array
    {
        $lines = [];
        if ($iue > 0) {
            $lines[] = $this->line(Setting::get('accounting_iue_expense_code', '6.8'), $iue, 0, 'Provisión IUE 25%');
            $lines[] = $this->line(Setting::get('accounting_iue_payable_code', '2.1.13'), 0, $iue, 'IUE por Pagar');
        }
        if ($reserva > 0) {
            $lines[] = $this->line(Setting::get('accounting_period_result_code', '3.3'), $reserva, 0, 'Apropiación reserva legal');
            $lines[] = $this->line(Setting::get('accounting_legal_reserve_code', '3.4'), 0, $reserva, 'Reserva Legal');
        }

        return $lines;
    }

    protected function line(string $code, int $debit, int $credit, string $desc): array
    {
        return [
            'chart_of_account_id' => $this->accountId($code),
            'debit_amount' => $debit,
            'credit_amount' => $credit,
            'description' => $desc,
        ];
    }

    protected function accountId(string $code): int
    {
        $account = ChartOfAccount::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->where('allows_posting', true)
            ->first();

        if (! $account) {
            throw new RuntimeException("No existe cuenta contable activa/imputable con código {$code}.");
        }

        return $account->id;
    }
}
