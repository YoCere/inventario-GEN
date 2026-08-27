<?php

namespace App\Services\Accounting;

use App\DTOs\OpeningBalanceData;
use App\Enums\JournalEntryType;
use App\Enums\VoucherType;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OpeningBalanceService
{
    public function __construct(protected JournalEntryService $journalEntryService) {}

    /** Autopropone saldos iniciales; Caja/Banco/CxC/CxP quedan en 0 para que el usuario los llene. */
    public function propose(string $date): OpeningBalanceData
    {
        $inventory = (int) DB::table('product_stocks')
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->sum(DB::raw('product_stocks.quantity * products.purchase_price'));

        $ppe = (int) FixedAsset::where('is_opening', true)->sum('acquisition_cost');
        $depreciation = (int) FixedAsset::where('is_opening', true)->sum('accumulated_depreciation');
        $loans = (int) Loan::where('is_opening', true)->sum('outstanding_balance');

        $partial = new OpeningBalanceData(
            cash: 0, bank: 0, receivable: 0,
            inventory: $inventory, ppe: $ppe, accumulated_depreciation: $depreciation,
            payable: 0, loans: $loans, capital: 0,
        );

        return new OpeningBalanceData(
            cash: 0, bank: 0, receivable: 0,
            inventory: $inventory, ppe: $ppe, accumulated_depreciation: $depreciation,
            payable: 0, loans: $loans, capital: $partial->balancingCapital(),
        );
    }

    public function post(OpeningBalanceData $data, string $date, int $userId): JournalEntry
    {
        if ($data->capital < 0) {
            throw new RuntimeException('El capital de apertura no puede ser negativo (los pasivos superan a los activos).');
        }

        return DB::transaction(function () use ($data, $date, $userId) {
            $year = Carbon::parse($date)->year;
            $period = AccountingPeriod::whereYear('start_date', $year)
                ->orderBy('start_date')
                ->lockForUpdate()
                ->first();

            if (! $period) {
                throw new RuntimeException("No existe período contable para la gestión {$year}.");
            }

            if ($this->journalEntryService->findPostedSourceEntry(AccountingPeriod::class, $period->id)) {
                throw new RuntimeException("Ya existe un asiento de apertura para la gestión {$year}.");
            }

            return $this->journalEntryService->createPostedEntry([
                'entry_date'           => $date,
                'accounting_period_id' => $period->id,
                'description'          => "Asiento de apertura gestión {$year}",
                'source_type'          => AccountingPeriod::class,
                'source_id'            => $period->id,
                'voucher_type'         => VoucherType::Apertura->value,
                'entry_type'           => JournalEntryType::Apertura->value,
                'created_by'           => $userId,
                'posted_by'            => $userId,
            ], $this->buildLines($data), allowClosedPeriod: true);
        });
    }

    /** @return array<int, array{chart_of_account_id:int,debit_amount:int,credit_amount:int,description:string}> */
    private function buildLines(OpeningBalanceData $data): array
    {
        $spec = [
            ['accounting_opening_cash_code',         $data->cash,                     'debit',  'Caja'],
            ['accounting_opening_bank_code',         $data->bank,                     'debit',  'Banco'],
            ['accounting_opening_receivable_code',   $data->receivable,               'debit',  'Cuentas por cobrar'],
            ['accounting_opening_inventory_code',    $data->inventory,                'debit',  'Inventario'],
            ['accounting_opening_ppe_code',          $data->ppe,                      'debit',  'Bienes de uso'],
            ['accounting_opening_depreciation_code', $data->accumulated_depreciation, 'credit', 'Depreciación acumulada'],
            ['accounting_opening_payable_code',      $data->payable,                  'credit', 'Cuentas por pagar'],
            ['accounting_opening_loan_code',         $data->loans,                    'credit', 'Préstamos por pagar'],
            ['accounting_opening_capital_code',      $data->capital,                  'credit', 'Capital social'],
        ];

        $lines = [];
        foreach ($spec as [$settingKey, $amount, $side, $glosa]) {
            if ($amount <= 0) {
                continue;
            }
            $account = $this->findPostingAccount(Setting::get($settingKey));
            $lines[] = [
                'chart_of_account_id' => $account->id,
                'description'         => 'Apertura: ' . $glosa,
                'debit_amount'        => $side === 'debit' ? $amount : 0,
                'credit_amount'       => $side === 'credit' ? $amount : 0,
            ];
        }

        return $lines;
    }

    private function findPostingAccount(string $code): ChartOfAccount
    {
        $account = ChartOfAccount::query()
            ->where('code', $code)->where('is_active', true)->where('allows_posting', true)->first();

        if (! $account) {
            throw new RuntimeException("No existe cuenta contable activa/imputable con código {$code}.");
        }

        return $account;
    }
}
