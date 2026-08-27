<?php

namespace App\Services\Accounting;

use App\DTOs\OpeningBalanceData;
use App\Models\FixedAsset;
use App\Models\Loan;
use Illuminate\Support\Facades\DB;

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
}
