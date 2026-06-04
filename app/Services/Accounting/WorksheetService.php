<?php

namespace App\Services\Accounting;

use App\Enums\AccountNormalBalance;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\Setting;
use App\Models\WorksheetAnnotation;
use App\Models\WorksheetRow;
use Illuminate\Support\Facades\DB;

class WorksheetService
{
    public function __construct(
        private LedgerBalanceService $balances,
        private WorksheetSuggestionEngine $suggestions,
    ) {
    }

    public function generate(AccountingPeriod $period): void
    {
        $start = $period->start_date->toDateString();
        $end   = $period->end_date->toDateString();
        $dayBefore = $period->start_date->copy()->subDay()->toDateString();

        $opening = $this->balances->balancesAt($dayBefore, includeAdjustments: true)->keyBy('chart_of_account_id');
        $normal  = $this->periodSums($start, $end, 'normal');
        $ajuste  = $this->periodSums($start, $end, 'ajuste');

        $accounts = ChartOfAccount::where('allows_posting', true)->orderBy('code')->get();

        $totalIngresos = 0; $totalGastos = 0;
        $prelim = [];
        foreach ($accounts as $acc) {
            // account_type / normal_balance están casteados a enum en el modelo;
            // normalizamos a su valor string para todas las comparaciones.
            $accountType = $acc->account_type instanceof \App\Enums\AccountType
                ? $acc->account_type->value : (string) $acc->account_type;
            $normalBalance = $acc->normal_balance instanceof AccountNormalBalance
                ? $acc->normal_balance->value : (string) $acc->normal_balance;

            $oi = $opening->get($acc->id);
            $openingBalance = $oi ? (int) $oi->balance : 0;
            $isDebit = $normalBalance === AccountNormalBalance::Debit->value;
            $iniDebe  = ($isDebit && $openingBalance > 0) ? $openingBalance : 0;
            $iniHaber = (!$isDebit && $openingBalance > 0) ? $openingBalance : 0;

            $nd = (int) ($normal[$acc->id]->sd ?? 0); $nc = (int) ($normal[$acc->id]->sc ?? 0);
            $ad = (int) ($ajuste[$acc->id]->sd ?? 0); $ac = (int) ($ajuste[$acc->id]->sc ?? 0);

            $netDebe  = $iniDebe + $nd + $ad;
            $netHaber = $iniHaber + $nc + $ac;
            $saldo = $isDebit ? $netDebe - $netHaber : $netHaber - $netDebe;

            $ajDebe  = $isDebit ? max($saldo, 0) : max(-$saldo, 0);
            $ajHaber = !$isDebit ? max($saldo, 0) : max(-$saldo, 0);

            if ($accountType === 'income') { $totalIngresos += $ajHaber; }
            if (in_array($accountType, ['expense', 'cost'], true)) { $totalGastos += $ajDebe; }

            $prelim[] = compact('acc', 'accountType', 'iniDebe', 'iniHaber', 'nd', 'nc', 'ad', 'ac', 'ajDebe', 'ajHaber', 'saldo');
        }

        $ctx = [
            'liquidity_target'  => (int) Setting::get('worksheet_liquidity_target', '1500000'),
            'high_expense_pct'  => (float) Setting::get('worksheet_high_expense_pct', '40'),
            'variation_pct'     => (float) Setting::get('worksheet_variation_pct', '50'),
        ];
        $liquidityCodes = explode(',', (string) Setting::get('worksheet_liquidity_accounts', '1.1.1.01'));

        // Variación vs saldo previo al periodo == saldo inicial; reusa $opening.
        $prevBalances = $opening;

        DB::transaction(function () use ($prelim, $prevBalances, $liquidityCodes, $ctx, $totalIngresos, $totalGastos, $period) {
        foreach ($prelim as $p) {
            $acc = $p['acc'];
            $accountType = $p['accountType'];
            $isResult = in_array($accountType, ['income', 'expense', 'cost'], true);
            $isBalance = in_array($accountType, ['asset', 'liability', 'equity'], true);

            $porcentaje = null;
            if ($accountType === 'income' && $totalIngresos > 0) {
                $porcentaje = round($p['ajHaber'] / $totalIngresos * 100, 2);
            } elseif (in_array($accountType, ['expense', 'cost'], true) && $totalGastos > 0) {
                $porcentaje = round($p['ajDebe'] / $totalGastos * 100, 2);
            }

            $prev = $prevBalances->get($acc->id);
            $variacion = ($prev && (int) $prev->balance !== 0)
                ? round(($p['saldo'] - (int) $prev->balance) / abs((int) $prev->balance) * 100, 2)
                : null;

            $isLiquidity = in_array($acc->code, $liquidityCodes, true);
            $suggested = $this->suggestions->evaluate([
                'account_type' => $accountType,
                'is_liquidity' => $isLiquidity,
                'saldo' => $p['saldo'],
                'porcentaje_total' => $porcentaje,
                'variacion_pct' => $variacion,
            ], $ctx);

            WorksheetRow::updateOrCreate(
                ['accounting_period_id' => $period->id, 'chart_of_account_id' => $acc->id],
                [
                    'saldo_inicial_debe' => $p['iniDebe'], 'saldo_inicial_haber' => $p['iniHaber'],
                    'mov_debito' => $p['nd'], 'mov_credito' => $p['nc'],
                    'ajuste_debito' => $p['ad'], 'ajuste_credito' => $p['ac'],
                    'saldo_aj_debe' => $p['ajDebe'], 'saldo_aj_haber' => $p['ajHaber'],
                    'result_debe' => $isResult ? $p['ajDebe'] : 0,
                    'result_haber' => $isResult ? $p['ajHaber'] : 0,
                    'balance_debe' => $isBalance ? $p['ajDebe'] : 0,
                    'balance_haber' => $isBalance ? $p['ajHaber'] : 0,
                    'variacion_pct' => $variacion,
                    'porcentaje_total' => $porcentaje,
                    'suggested_action' => $suggested,
                    'generated_at' => now(),
                ]
            );
        }
        });
    }

    private function periodSums(string $from, string $to, string $type): \Illuminate\Support\Collection
    {
        return DB::table('ledger_account_daily')
            ->whereDate('movement_date', '>=', $from)
            ->whereDate('movement_date', '<=', $to)
            ->where('entry_type', $type)
            ->selectRaw('chart_of_account_id, SUM(debit_total) as sd, SUM(credit_total) as sc')
            ->groupBy('chart_of_account_id')
            ->get()->keyBy('chart_of_account_id');
    }

    /**
     * @return array{filas: array<int,array<string,mixed>>, totales: array<string,int>,
     *   utilidad: int, cuadra: bool}
     */
    public function present(AccountingPeriod $period): array
    {
        $rows = WorksheetRow::where('accounting_period_id', $period->id)
            ->join('chart_of_accounts as c', 'c.id', '=', 'worksheet_rows.chart_of_account_id')
            ->orderBy('c.code')
            ->get(['worksheet_rows.*', 'c.code', 'c.name', 'c.account_type']);

        $notes = WorksheetAnnotation::where('accounting_period_id', $period->id)
            ->get()->keyBy('chart_of_account_id');

        $tot = ['result_debe' => 0, 'result_haber' => 0, 'balance_debe' => 0, 'balance_haber' => 0];
        $filas = [];
        foreach ($rows as $r) {
            $note = $notes->get($r->chart_of_account_id);
            $filas[] = [
                'chart_of_account_id' => (int) $r->chart_of_account_id,
                'code' => $r->code, 'name' => $r->name, 'account_type' => $r->account_type,
                'saldo_inicial_debe' => (int) $r->saldo_inicial_debe, 'saldo_inicial_haber' => (int) $r->saldo_inicial_haber,
                'mov_debito' => (int) $r->mov_debito, 'mov_credito' => (int) $r->mov_credito,
                'ajuste_debito' => (int) $r->ajuste_debito, 'ajuste_credito' => (int) $r->ajuste_credito,
                'saldo_aj_debe' => (int) $r->saldo_aj_debe, 'saldo_aj_haber' => (int) $r->saldo_aj_haber,
                'result_debe' => (int) $r->result_debe, 'result_haber' => (int) $r->result_haber,
                'balance_debe' => (int) $r->balance_debe, 'balance_haber' => (int) $r->balance_haber,
                'variacion_pct' => $r->variacion_pct, 'porcentaje_total' => $r->porcentaje_total,
                'suggested_action' => $note && $note->manual_note ? $note->manual_note : $r->suggested_action,
                'manual_note' => $note?->manual_note,
                'action_status' => $note?->action_status ?? 'pendiente',
            ];
            $tot['result_debe'] += (int) $r->result_debe;
            $tot['result_haber'] += (int) $r->result_haber;
            $tot['balance_debe'] += (int) $r->balance_debe;
            $tot['balance_haber'] += (int) $r->balance_haber;
        }

        $utilidad = $tot['result_haber'] - $tot['result_debe'];
        $tot['balance_haber_con_utilidad'] = $tot['balance_haber'] + max($utilidad, 0);
        $balanceDebeAjustado = $tot['balance_debe'] + max(-$utilidad, 0);

        $cuadra = $balanceDebeAjustado === $tot['balance_haber_con_utilidad'];

        return ['filas' => $filas, 'totales' => $tot, 'utilidad' => $utilidad, 'cuadra' => $cuadra];
    }
}
