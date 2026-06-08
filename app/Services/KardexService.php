<?php

namespace App\Services;

use App\Enums\PurchaseStatus;
use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\SaleItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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
    public function build(int $productId, string $from, string $to, ?int $locationId = null): array
    {
        $fromDate = Carbon::parse($from)->startOfDay();
        $toDate = Carbon::parse($to)->endOfDay();

        $product = Product::query()->findOrFail($productId);
        $movements = $this->loadMovements($productId, $toDate, $locationId);

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

                // CRITICAL: Mantener negativos visibles para auditoría
                // Resetear a 0 oculta overselling y rompe trazabilidad financiera
                $balanceQty -= $movement['qty'];
                $balanceValue -= $exitTotal;

                $oversold = $balanceQty < 0;

                if ($oversold) {
                    Log::warning('Kardex oversold detected', [
                        'product_id' => $productId,
                        'date' => $date->toDateString(),
                        'reference' => $movement['reference'],
                        'balance_qty_negative' => $balanceQty,
                    ]);
                }

                $averageCost = $balanceQty > 0 ? ($balanceValue / $balanceQty) : 0.0;

                if ($isInPeriod) {
                    $exitQtyTotal += $movement['qty'];
                    $exitValueTotal += $exitTotal;

                    $rows[] = [
                        'date' => $date->format('d/m/Y'),
                        'detail' => $oversold ? 'Salida por venta ⚠️ OVERSOLD' : 'Salida por venta',
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
                        'oversold' => $oversold,
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

        // INTEGRITY CHECK: comparar kardex calculado vs stock actual en BD
        $actualDbQty = (int) $product->quantity;
        $integrityOk = $balanceQty === $actualDbQty;
        $integrityDiff = $actualDbQty - $balanceQty;

        if (!$integrityOk) {
            Log::warning('Kardex integrity mismatch', [
                'product_id' => $productId,
                'product_sku' => $product->sku,
                'kardex_calculated' => $balanceQty,
                'db_actual' => $actualDbQty,
                'difference' => $integrityDiff,
                'period_to' => $toDate->toDateString(),
            ]);
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
            'integrity' => [
                'ok' => $integrityOk,
                'kardex_qty' => $balanceQty,
                'db_qty' => $actualDbQty,
                'difference' => $integrityDiff,
            ],
        ];
    }

    /**
     * Returns the current weighted-average unit cost for a product (in cents),
     * calculated from the Kardex balance at the given date.
     */
    public function averageUnitCost(int $productId, ?string $asOf = null, ?int $locationId = null): int
    {
        $to = $asOf ?: now()->toDateString();
        $kardex = $this->build($productId, '1900-01-01', $to, $locationId);
        $qty = (int) $kardex['totals']['closing_qty'];
        $value = (float) $kardex['totals']['closing_total'];
        return $qty > 0 ? (int) round($value / $qty) : 0;
    }

    /**
     * @return Collection<int, array{date:string,type:string,qty:int,unit_cost:float,reference:string,sort_time:string}>
     */
    protected function loadMovements(int $productId, Carbon $toDate, ?int $locationId = null): Collection
    {
        $entries = PurchaseItem::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchase_items.product_id', $productId)
            ->when($locationId, fn ($q) => $q->where('purchase_items.location_id', $locationId))
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
            ->when($locationId, fn ($q) => $q->where('sale_items.location_id', $locationId))
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

        $consumptions = \App\Models\ProductionConsumption::query()
            ->join('production_orders', 'production_orders.id', '=', 'production_consumptions.production_order_id')
            ->where('production_consumptions.component_product_id', $productId)
            ->when($locationId, fn ($q) => $q->where('production_orders.location_id', $locationId))
            ->whereDate('production_orders.production_date', '<=', $toDate->toDateString())
            ->select([
                'production_orders.production_date as date',
                'production_orders.code as reference',
                'production_consumptions.quantity as qty',
                'production_consumptions.unit_cost as unit_cost',
                'production_orders.created_at as sort_time',
            ])
            ->get()
            ->map(fn ($row) => [
                'date'      => Carbon::parse($row->date)->toDateString(),
                'type'      => 'exit',
                'qty'       => (int) round((float) $row->qty),
                'unit_cost' => (float) $row->unit_cost,
                'reference' => (string) ($row->reference ?: 'PRD'),
                'sort_time' => (string) $row->sort_time,
            ]);

        $productions = \App\Models\ProductionOrder::query()
            ->where('product_id', $productId)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->whereDate('production_date', '<=', $toDate->toDateString())
            ->select([
                'production_date as date',
                'code as reference',
                'quantity as qty',
                'total_cost',
                'created_at as sort_time',
            ])
            ->get()
            ->map(fn ($row) => [
                'date'      => Carbon::parse($row->date)->toDateString(),
                'type'      => 'entry',
                'qty'       => (int) $row->qty,
                'unit_cost' => (int) $row->qty > 0 ? ((float) $row->total_cost / (int) $row->qty) : 0.0,
                'reference' => (string) ($row->reference ?: 'PRD'),
                'sort_time' => (string) $row->sort_time,
            ]);

        return $entries
            ->concat($exits)
            ->concat($consumptions)
            ->concat($productions)
            ->sortBy([
                ['date', 'asc'],
                ['type', 'asc'], // entry first, then exit
                ['sort_time', 'asc'],
            ])
            ->values();
    }
}

