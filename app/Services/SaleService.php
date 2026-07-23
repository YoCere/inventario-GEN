<?php

namespace App\Services;

use Exception;
use App\Models\Sale;
use App\DTOs\SaleData;
use App\Models\Product;
use App\Enums\SaleStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\SaleException;
use App\Services\Accounting\SaleAccountingService;
use Illuminate\Support\Facades\DB;

class SaleService
{
    public function __construct(
        protected FinanceTransactionService $financeService,
        protected SaleAccountingService $saleAccountingService,
        protected AuditService $auditService,
        protected StockService $stockService,
        protected \App\Fiscal\SaleTaxCalculator $taxCalculator
    ) {
    }

    /**
     * Create a new sale with items and deduction of stock.
     */
    public function createSale(SaleData $data): Sale
    {
        return DB::transaction(function () use ($data) {
            try {
                // Lock products for update
                $productIds = collect($data->items)->pluck('product_id')->sort()->values()->all();

                $products = Product::whereIn('id', $productIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $sale = Sale::create([
                    'invoice_number' => $this->generateInvoiceNumber(),
                    'customer_id' => $data->customer_id,
                    'buyer_name' => $data->buyer_name,
                    'buyer_phone' => $data->buyer_phone,
                    'created_by' => $data->created_by,
                    'sale_date' => $data->sale_date,
                    'status' => $data->status,
                    'payment_method' => $data->payment_method,
                    'source' => $data->source,
                    'notes' => $data->notes,
                    'cash_received' => $data->cash_received,
                    'change' => $data->change,
                    'subtotal' => 0,
                    'global_discount' => $data->global_discount,
                    'total_discount' => 0,
                    'total' => 0,
                ]);

                $totalSubtotal = 0;
                $totalDiscount = 0;
                $timestamp = now();
                $saleItems = [];

                foreach ($data->items as $itemData) {
                    $product = $products->get($itemData->product_id);

                    if (!$product) {
                        throw SaleException::productNotFound($itemData->product_id);
                    }

                    // FIFO pick: find a single location with enough stock (lowest id first)
                    $pickedStock = $this->stockService->pickFifoLocationForSale(
                        $product->id,
                        $itemData->quantity
                    );

                    if (!$pickedStock) {
                        $totalAvailable = $this->stockService->totalStock($product->id);
                        throw SaleException::insufficientStock(
                            $product->name,
                            $itemData->quantity,
                            $totalAvailable
                        );
                    }

                    $oldLocationQty = $pickedStock->quantity;
                    $sourceLocationId = $pickedStock->location_id;

                    // Decrement at the picked location (also syncs products.quantity)
                    $this->stockService->decrementAt(
                        $product->id,
                        $sourceLocationId,
                        $itemData->quantity
                    );

                    $product->refresh();

                    $this->auditService->log(
                        'stock.decremento.venta',
                        $product,
                        ['quantity_location' => $oldLocationQty],
                        [
                            'quantity_location' => $oldLocationQty - $itemData->quantity,
                            'cantidad_vendida' => $itemData->quantity,
                            'location_id' => $sourceLocationId,
                            'sale_invoice' => $sale->invoice_number,
                            'sale_id' => $sale->id,
                        ],
                        $data->created_by ?? null
                    );

                    $unitPrice = $product->selling_price;
                    $quantity = $itemData->quantity;
                    $discount = $itemData->discount;

                    if ($discount > $unitPrice) {
                        throw SaleException::invalidDiscount("Item discount (" . format_money($discount) . ") cannot exceed unit price (" . format_money($unitPrice) . ") for product '{$product->name}'.");
                    }

                    $finalPrice = $unitPrice - $discount;
                    $subtotal   = $finalPrice * $quantity;

                    $saleItems[] = [
                        'sale_id' => $sale->id,
                        'product_id' => $product->id,
                        'location_id' => $sourceLocationId,
                        'quantity' => $quantity,
                        'cost_price' => $product->purchase_price,
                        'unit_price' => $unitPrice,
                        'discount' => $discount,
                        'final_price' => $finalPrice,
                        'subtotal' => $subtotal,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];

                    $totalSubtotal += $subtotal;
                    $totalDiscount += $discount * $quantity;
                }

                // Batch insert items
                if (!empty($saleItems)) {
                    \App\Models\SaleItem::insert($saleItems);
                }

                if ($data->global_discount > $totalSubtotal) {
                    throw SaleException::invalidDiscount("Global discount (" . format_money($data->global_discount) . ") cannot exceed subtotal (" . format_money($totalSubtotal) . ").");
                }

                $total = $totalSubtotal - $data->global_discount;

                if ($data->status === SaleStatus::COMPLETED) {
                    if ($data->payment_method === \App\Enums\PaymentMethod::CASH && $data->cash_received < $total) {
                        throw SaleException::insufficientPayment($total, $data->cash_received);
                    }
                }
                $change = 0;

                // Calculate change if payment method is cash
                if ($data->payment_method === \App\Enums\PaymentMethod::CASH && $data->cash_received >= $total) {
                    $change = $data->cash_received - $total;
                }

                $tax = $this->taxCalculator->forTotal($total);

                $sale->update([
                    'subtotal' => $totalSubtotal + $totalDiscount,
                    'total_discount' => $totalDiscount + $data->global_discount,
                    'global_discount' => $data->global_discount,
                    'total' => $total,
                    'change' => $change,
                    'taxable_base' => $tax['taxable_base'],
                    'iva_amount' => $tax['iva_amount'],
                    'it_amount' => $tax['it_amount'],
                    'wants_invoice' => $data->wants_invoice,
                ]);

                if ($sale->status === SaleStatus::COMPLETED) {
                    $this->financeService->recordIncomeFromSale($sale);
                    $this->saleAccountingService->postCompletedSale($sale, (int) ($sale->created_by ?? 1));
                }

                return $sale;

            } catch (Exception $e) {
                if ($e instanceof SaleException)
                    throw $e;
                throw SaleException::creationFailed($e->getMessage(), ['data' => $data]);
            }
        });
    }

    /**
     * Cancel a sale and restore stock.
     */
    public function cancelSale(Sale $sale, ?string $reason = null): Sale
    {
        return DB::transaction(function () use ($sale, $reason) {
            try {
                if ($sale->status === SaleStatus::CANCELLED) {
                    throw SaleException::invalidStatus('cancel', $sale->status->label(), ['id' => $sale->id]);
                }

                // Capturar status original ANTES de mutar — necesario para decidir si revertir GL.
                $wasCompleted = $sale->status === SaleStatus::COMPLETED;

                // Restore stock for completed or pending sales
                if (in_array($sale->status, [SaleStatus::COMPLETED, SaleStatus::PENDING])) {
                    $sale->loadMissing('items.product');

                    foreach ($sale->items as $item) {
                        if (!$item->product) {
                            continue;
                        }

                        // Restore to original location (or default for legacy data with NULL)
                        $targetLocationId = $item->location_id ?? $this->stockService->defaultLocationId();

                        $this->stockService->incrementAt(
                            $item->product_id,
                            $targetLocationId,
                            $item->quantity
                        );

                        $item->product->refresh();

                        $this->auditService->log(
                            'stock.incremento.cancelacion',
                            $item->product,
                            null,
                            [
                                'quantity_actual' => $item->product->quantity,
                                'cantidad_restaurada' => $item->quantity,
                                'location_id' => $targetLocationId,
                                'sale_invoice' => $sale->invoice_number,
                                'sale_id' => $sale->id,
                                'motivo' => $reason,
                            ]
                        );
                    }
                }

                $updateData = ['status' => SaleStatus::CANCELLED];

                if ($reason) {
                    $updateData['notes'] = ($sale->notes ? $sale->notes . "\n" : '') . "[Cancelled]: " . $reason;
                }

                $sale->update($updateData);

                // Void Finance
                $this->financeService->voidTransaction($sale);

                // Solo revertir GL si la venta estaba COMPLETED (PENDING no genera asiento).
                // Si reverseSaleEntry devuelve null estando COMPLETED → asiento contable ausente
                // = corrupción de datos. Abortar para evitar inconsistencia GL vs estado venta.
                if ($wasCompleted) {
                    $reversal = $this->saleAccountingService->reverseSaleEntry(
                        $sale,
                        (int) (auth()->id() ?? $sale->created_by ?? 1),
                        $reason
                    );
                    if (!$reversal) {
                        throw SaleException::cancellationFailed(
                            'No se encontró asiento contable activo para revertir.',
                            ['id' => $sale->id, 'invoice' => $sale->invoice_number]
                        );
                    }
                }

                return $sale;

            } catch (Exception $e) {
                if ($e instanceof SaleException)
                    throw $e;
                throw SaleException::cancellationFailed($e->getMessage(), ['id' => $sale->id]);
            }
        });
    }

    /**
     * Mark a pending sale as completed.
     */
    public function completeSale(Sale $sale, array $paymentData = []): Sale
    {
        return DB::transaction(function () use ($sale, $paymentData) {
            if ($sale->status !== SaleStatus::PENDING) {
                throw SaleException::invalidStatus('complete', $sale->status->label(), ['id' => $sale->id]);
            }

            $updateData = ['status' => SaleStatus::COMPLETED];

            if (!empty($paymentData)) {
                $updateData['cash_received'] = $paymentData['cash_received'] ?? $sale->cash_received;

                if ($sale->payment_method === PaymentMethod::CASH && $updateData['cash_received'] < $sale->total) {
                    throw SaleException::insufficientPayment($sale->total, $updateData['cash_received']);
                }

                // Calculate Change
                if ($sale->payment_method === PaymentMethod::CASH && $updateData['cash_received'] >= $sale->total) {
                    $updateData['change'] = $updateData['cash_received'] - $sale->total;
                } else {
                    $updateData['change'] = 0;
                }
            }

            $sale->update($updateData);

            // Sync Finance
            $this->financeService->recordIncomeFromSale($sale);
            $this->saleAccountingService->postCompletedSale($sale, (int) ($sale->created_by ?? auth()->id() ?? 1));

            return $sale;
        });
    }

    /**
     * Restore a cancelled sale to pending (must reserve stock again).
     */
    public function restoreSale(Sale $sale): Sale
    {
        return DB::transaction(function () use ($sale) {
            if ($sale->status !== SaleStatus::CANCELLED) {
                throw SaleException::invalidStatus('restore', $sale->status->label(), ['id' => $sale->id]);
            }

            // Must re-deduct stock from the original location (or default if legacy)
            $sale->loadMissing('items.product');

            foreach ($sale->items as $item) {
                $product = $item->product;

                if (!$product) {
                    throw SaleException::productNotFound($item->product_id);
                }

                $targetLocationId = $item->location_id ?? $this->stockService->defaultLocationId();

                // decrementAt will throw if insufficient at that specific location
                try {
                    $this->stockService->decrementAt(
                        $item->product_id,
                        $targetLocationId,
                        $item->quantity
                    );
                } catch (\RuntimeException $e) {
                    throw SaleException::insufficientStock(
                        $product->name,
                        $item->quantity,
                        $this->stockService->totalStock($item->product_id)
                    );
                }

                $product->refresh();

                $this->auditService->log(
                    'stock.decremento.restauracion',
                    $product,
                    null,
                    [
                        'quantity_actual' => $product->quantity,
                        'cantidad_descontada' => $item->quantity,
                        'location_id' => $targetLocationId,
                        'sale_invoice' => $sale->invoice_number,
                        'sale_id' => $sale->id,
                    ]
                );
            }

            // Restore to PENDING
            $sale->update(['status' => SaleStatus::PENDING]);

            // No Finance Sync needed as it goes to PENDING

            return $sale;
        });
    }

    /**
     * Permanently delete a cancelled sale.
     *
     * @param Sale $sale
     * @return void
     * @throws Exception
     */
    public function deleteSale(Sale $sale): void
    {
        DB::transaction(function () use ($sale) {
            if ($sale->status !== SaleStatus::CANCELLED) {
                throw SaleException::invalidStatus('delete', $sale->status->label(), ['id' => $sale->id]);
            }

            // Void Finance (Just in case)
            $this->financeService->voidTransaction($sale);

            // Manually delete items first due to restrictOnDelete constraint
            $sale->items()->delete();
            $sale->delete();
        });
    }

    /**
     * Generate unique invoice number atomically.
     * Format: INV.YYMMDD.0001
     * Uses lockForUpdate to prevent race condition on concurrent sales.
     * Retries up to 5 times if unique constraint collision still occurs.
     */
    private function generateInvoiceNumber(): string
    {
        $prefix = 'INV.' . date('ymd') . '.';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            // lockForUpdate prevents two concurrent transactions from reading same lastNumber
            $latest = Sale::where('invoice_number', 'like', $prefix . '%')
                ->orderBy('id', 'desc')
                ->lockForUpdate()
                ->first();

            $lastNumber = $latest ? (int) substr($latest->invoice_number, -6) : 0;
            $candidate = $prefix . str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);

            // Defensive check: ensure candidate not already taken (covers gap edge cases)
            if (!Sale::where('invoice_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw SaleException::creationFailed(
            'No se pudo generar número de factura único tras múltiples intentos.',
            ['prefix' => $prefix]
        );
    }
}
