<?php

namespace App\Services\Accounting;

use App\Enums\JournalEntryStatus;
use App\Enums\JournalEntryType;
use App\Enums\VoucherType;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Setting;
use App\Services\FinancialStatementService;
use Illuminate\Support\Facades\DB;
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

    public function close(int $year, int $userId): ?JournalEntry
    {
        return DB::transaction(function () use ($year, $userId) {
            $period = AccountingPeriod::whereYear('start_date', $year)->orderBy('start_date')->lockForUpdate()->first();
            if (! $period) {
                throw new RuntimeException("No existe período contable para la gestión {$year}.");
            }
            if ($this->cierreExists($period->id)) {
                throw new RuntimeException("La gestión {$year} ya está cerrada.");
            }

            $er = $this->statements->build("{$year}-01-01", "{$year}-12-31", withTaxes: true)['estado_resultados'];
            $lines = $this->buildLines((int) $er['iue'], (int) $er['reserva_legal']);
            if (empty($lines)) {
                return null;
            }

            return $this->journalEntryService->createPostedEntry([
                'entry_date' => "{$year}-12-31",
                'accounting_period_id' => $period->id,
                'description' => "Cierre de gestión {$year}: provisión IUE y reserva legal",
                'source_type' => AccountingPeriod::class,
                'source_id' => $period->id,
                'voucher_type' => VoucherType::Cierre->value,
                'entry_type' => JournalEntryType::Cierre->value,
                'created_by' => $userId,
                'posted_by' => $userId,
            ], $lines, allowClosedPeriod: true);
        });
    }

    protected function alreadyClosed(int $year): bool
    {
        $period = AccountingPeriod::whereYear('start_date', $year)->orderBy('start_date')->first();

        return $period ? $this->cierreExists($period->id) : false;
    }

    /** Guard de unicidad: existe un asiento de CIERRE posteado para el período (no confundir con apertura). */
    protected function cierreExists(int $periodId): bool
    {
        return JournalEntry::query()
            ->where('source_type', AccountingPeriod::class)
            ->where('source_id', $periodId)
            ->where('entry_type', JournalEntryType::Cierre->value)
            ->where('status', JournalEntryStatus::Posted)
            ->exists();
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
