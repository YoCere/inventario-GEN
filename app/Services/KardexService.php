<?php

namespace App\Services;

use App\Enums\PurchaseStatus;
use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\SaleItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class KardexService
{
    /**
     * @return array{
     *   product: Product,
     *   from: string,
     *   to: string,
     *   opening: array{qty:int,value:float,avg:float},
     *   rows: array<int, array{
     *      date:string,
     *      detail:string,
     *      reference:string,
     *      entry_qty:int,
     *      entry_unit:float,
     *      entry_total:float,
     *      exit_qty:int,
     *      exit_unit:float,
     *      exit_total:float,
     *      balance_qty:int,
     *      balance_unit:float,
     *      balance_total:float
     *   }>,
     *   totals: array{
     *      entry_qty:int,
     *      entry_total:float,
     *      exit_qty:int,
     *      exit_total:float,
     *      closing_qty:int,
     *      closing_total:float
     *   }
     * }
     */
    public function build(int $productId, string $from, string $to): array
    {
        $fromDate = Carbon::parse($from)->startOfDay();
        $toDate = Carbon::parse($to)->endOfDay();

        $product = Product::query()->findOrFail($productId);
        $movements = $this->loadMovements($productId, $toDate);

        $balanceQty = 0;
        $balanceValue = 0.0;
        $averageCost = 0.0;

        $opening = [
            'qty' => 0,
            'value' => 0.0,
            'avg' => 0.0,
        ];

        $rows = [];
        $entryQtyTotal = 0;
        $entryValueTotal = 0.0;
        $exitQtyTotal = 0;
        $exitValueTotal = 0.0;

        foreach ($movements as $movement) {
            $date = Carbon::parse($movement['date']);
            $isInPeriod = $date->betweenIncluded($fromDate, $toDate);

            if ($movement['type'] === 'entry') {
                $entryTotal = $movement['qty'] * $movement['unit_cost'];
                $balanceQty += $movement['qty'];
                $balanceValue += $entryTotal;
                $averageCost = $balanceQty > 0 ? ($balanceValue / $balanceQty) : 0.0;

                if ($isInPeriod) {
                    $entryQtyTotal += $movement['qty'];
                    $entryValueTotal += $entryTotal;

                    $rows[] = [
                        'date' => $date->format('d/m/Y'),
                        'detail' => 'Compra recibida',
                        'reference' => $movement['reference'],
                        'entry_qty' => $movement['qty'],
                        'entry_unit' => $movement['unit_cost'],
                        'entry_total' => $entryTotal,
                        'exit_qty' => 0,
                        'exit_unit' => 0.0,
                        'exit_total' => 0.0,
                        'balance_qty' => $balanceQty,
                        'balance_unit' => $averageCost,
                        'balance_total' => $balanceValue,
                    ];
                }
            } else {
                // Valoramos salidas al costo promedio ponderado movil (estilo kardex)
                $exitUnit = $averageCost;
                $exitTotal = $movement['qty'] * $exitUnit;

                $balanceQty -= $movement['qty'];
                if ($balanceQty < 0) {
                    $balanceQty = 0;
                }

                $balanceValue -= $exitTotal;
                if ($balanceValue < 0) {
                    $balanceValue = 0.0;
                }

                $averageCost = $balanceQty > 0 ? ($balanceValue / $balanceQty) : 0.0;

                if ($isInPeriod) {
                    $exitQtyTotal += $movement['qty'];
                    $exitValueTotal += $exitTotal;

                    $rows[] = [
                        'date' => $date->format('d/m/Y'),
                        'detail' => 'Salida por venta',
                        'reference' => $movement['reference'],
                        'entry_qty' => 0,
                        'entry_unit' => 0.0,
                        'entry_total' => 0.0,
                        'exit_qty' => $movement['qty'],
                        'exit_unit' => $exitUnit,
                        'exit_total' => $exitTotal,
                        'balance_qty' => $balanceQty,
                        'balance_unit' => $averageCost,
                        'balance_total' => $balanceValue,
                    ];
                }
            }

            if ($date->lt($fromDate)) {
                $opening = [
                    'qty' => $balanceQty,
                    'value' => $balanceValue,
                    'avg' => $averageCost,
                ];
            }
        }

        return [
            'product' => $product,
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'opening' => $opening,
            'rows' => $rows,
            'totals' => [
                'entry_qty' => $entryQtyTotal,
                'entry_total' => $entryValueTotal,
                'exit_qty' => $exitQtyTotal,
                'exit_total' => $exitValueTotal,
                'closing_qty' => $balanceQty,
                'closing_total' => $balanceValue,
            ],
        ];
    }

    /**
     * @return Collection<int, array{date:string,type:string,qty:int,unit_cost:float,reference:string,sort_time:string}>
     */
    protected function loadMovements(int $productId, Carbon $toDate): Collection
    {
        $entries = PurchaseItem::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchase_items.product_id', $productId)
            ->whereIn('purchases.status', [PurchaseStatus::RECEIVED->value, PurchaseStatus::PAID->value])
            ->whereDate('purchases.purchase_date', '<=', $toDate->toDateString())
            ->select([
                'purchases.purchase_date as date',
                'purchases.invoice_number as reference',
                'purchase_items.quantity as qty',
                'purchase_items.unit_price as unit_cost',
                'purchases.created_at as sort_time',
            ])
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->date,
                'type' => 'entry',
                'qty' => (int) $row->qty,
                'unit_cost' => (float) $row->unit_cost,
                'reference' => (string) ($row->reference ?: ('CMP-' . $row->sort_time)),
                'sort_time' => (string) $row->sort_time,
            ]);

        $exits = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sale_items.product_id', $productId)
            ->whereIn('sales.status', [SaleStatus::PENDING->value, SaleStatus::COMPLETED->value])
            ->whereDate('sales.sale_date', '<=', $toDate->toDateString())
            ->select([
                'sales.sale_date as date',
                'sales.invoice_number as reference',
                'sale_items.quantity as qty',
                'sale_items.cost_price as unit_cost',
                'sales.created_at as sort_time',
            ])
            ->get()
            ->map(fn ($row) => [
                'date' => Carbon::parse($row->date)->toDateString(),
                'type' => 'exit',
                'qty' => (int) $row->qty,
                'unit_cost' => (float) $row->unit_cost,
                'reference' => (string) ($row->reference ?: ('VTA-' . $row->sort_time)),
                'sort_time' => (string) $row->sort_time,
            ]);

        return $entries
            ->concat($exits)
            ->sortBy([
                ['date', 'asc'],
                ['type', 'asc'], // entry first, then exit
                ['sort_time', 'asc'],
            ])
            ->values();
    }
}

