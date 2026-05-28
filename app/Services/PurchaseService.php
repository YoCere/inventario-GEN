<?php

namespace App\Services;

use Exception;
use App\Models\Product;
use App\Models\Purchase;
use App\DTOs\PurchaseData;
use App\Models\PurchaseItem;
use App\Enums\PurchaseStatus;
use App\Services\Accounting\PurchaseAccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use App\Exceptions\PurchaseException;
use App\Events\LowStockDetected;

class PurchaseService
{
    public function __construct(
        protected FinanceTransactionService $financeService,
        protected PurchaseAccountingService $purchaseAccountingService,
        protected AuditService $auditService,
        protected StockService $stockService
    ) {
    }

    public function createPurchase(PurchaseData $data, int $userId): Purchase
    {
        return DB::transaction(function () use ($data, $userId) {
            try {
                $purchase = Purchase::create([
                    'invoice_number' => $data->invoice_number,
                    'supplier_id' => $data->supplier_id,
                    'purchase_date' => $data->purchase_date,
                    'due_date' => $data->due_date,
                    'status' => $data->status,
                    'notes' => $data->notes,
                    'proof_image' => $data->proof_image,
                    'created_by'     => $userId,
                    'total'          => 0,
                ]);

                $this->syncItems($purchase, $data->items);

                return $purchase;

            } catch (Exception $e) {
                throw PurchaseException::creationFailed($e->getMessage(), ['supplier_id' => $data->supplier_id]);
            }
        });
    }

    public function updatePurchase(Purchase $purchase, PurchaseData $data): Purchase
    {
        return DB::transaction(function () use ($purchase, $data) {
            try {
                if (!in_array($purchase->status, [PurchaseStatus::DRAFT, PurchaseStatus::ORDERED])) {
                    throw PurchaseException::invalidStatus('update', $purchase->status->label(), ['id' => $purchase->id]);
                }

                $purchase->update([
                    'invoice_number' => $data->invoice_number,
                    'supplier_id' => $data->supplier_id,
                    'purchase_date' => $data->purchase_date,
                    'due_date' => $data->due_date,
                    'notes' => $data->notes,
                    'proof_image' => $data->proof_image,
                ]);

                // Full sync of items
                $purchase->items()->delete();
                $this->syncItems($purchase, $data->items);

                return $purchase->refresh();

            } catch (Exception $e) {
                if ($e instanceof PurchaseException)
                    throw $e;
                throw PurchaseException::updateFailed($e->getMessage(), ['id' => $purchase->id]);
            }
        });
    }

    public function deletePurchase(Purchase $purchase): void
    {
        DB::transaction(function () use ($purchase) {
            try {
                if (!in_array($purchase->status, [PurchaseStatus::DRAFT, PurchaseStatus::CANCELLED])) {
                    throw PurchaseException::deletionFailed(
                        "Cannot delete purchase with status [{$purchase->status->label()}]. Only Draft or Cancelled purchases can be deleted.",
                        ['id' => $purchase->id, 'status' => $purchase->status->value]
                    );
                }

                $this->financeService->voidTransaction($purchase);

                $purchase->items()->delete();
                $purchase->delete();

            } catch (Exception $e) {
                if ($e instanceof PurchaseException)
                    throw $e;
                throw PurchaseException::deletionFailed($e->getMessage(), ['id' => $purchase->id]);
            }
        });
    }

    public function markAsOrdered(Purchase $purchase): void
    {
        DB::transaction(function () use ($purchase) {
            if ($purchase->status !== PurchaseStatus::DRAFT) {
                throw PurchaseException::invalidStatus('order', $purchase->status->label(), ['id' => $purchase->id]);
            }

            if ($purchase->items()->count() === 0) {
                throw PurchaseException::updateFailed("No se puede ordenar una compra sin artículos.", ['id' => $purchase->id]);
            }

            $purchase->update(['status' => PurchaseStatus::ORDERED]);
        });
    }

    public function markAsReceived(Purchase $purchase): void
    {
        DB::transaction(function () use ($purchase) {
            if (!in_array($purchase->status, [PurchaseStatus::ORDERED, PurchaseStatus::DRAFT])) {
                throw PurchaseException::invalidStatus('receive', $purchase->status->label(), ['id' => $purchase->id]);
            }

            if (empty($purchase->invoice_number)) {
                throw PurchaseException::missingReference('Número de factura', ['id' => $purchase->id]);
            }

            // Enforce Proof Image
            if (empty($purchase->proof_image)) {
                throw PurchaseException::missingReference('Comprobante de imagen', ['id' => $purchase->id]);
            }

            // Default location for received stock (FASE 3 decision: auto-assign to default)
            $defaultLocationId = $this->stockService->defaultLocationId();

            // Update Stock
            foreach ($purchase->items as $item) {
                // Lock the product row for update to prevent race conditions
                $product = Product::where('id', $item->product_id)->lockForUpdate()->first();

                if ($product) {
                    $oldPurchasePrice = $product->purchase_price;
                    $oldSellingPrice = $product->selling_price;

                    // Increment stock at default location (creates stock row if missing)
                    $this->stockService->incrementAt(
                        $product->id,
                        $defaultLocationId,
                        $item->quantity
                    );

                    // Persist receipt destination on the purchase_item
                    $item->update(['location_id' => $defaultLocationId]);

                    $product->refresh();

                    // Audit stock movement
                    $this->auditService->log(
                        'stock.incremento.compra',
                        $product,
                        null,
                        [
                            'quantity_actual' => $product->quantity,
                            'cantidad_recibida' => $item->quantity,
                            'location_id' => $defaultLocationId,
                            'purchase_invoice' => $purchase->invoice_number,
                            'purchase_id' => $purchase->id,
                        ],
                        $purchase->created_by
                    );

                    // Update latest purchase price and selling price
                    $updateData = ['purchase_price' => $item->unit_price];
                    $priceChanges = [];

                    // Check for Purchase Price Change
                    if ((int) $oldPurchasePrice !== (int) $item->unit_price) {
                        $priceChanges['purchase_price'] = [
                            'old' => $oldPurchasePrice,
                            'new' => $item->unit_price,
                        ];
                    }

                    // Check for Selling Price Change
                    if ($item->selling_price) {
                        $updateData['selling_price'] = $item->selling_price;
                        if ((int) $oldSellingPrice !== (int) $item->selling_price) {
                            $priceChanges['selling_price'] = [
                                'old' => $oldSellingPrice,
                                'new' => $item->selling_price,
                            ];
                        }
                    }

                    $product->update($updateData);

                    // Audit price changes via audit_logs (no longer in notes field)
                    if (!empty($priceChanges)) {
                        $this->auditService->log(
                            'producto.precio_actualizado_por_compra',
                            $product,
                            array_combine(
                                array_keys($priceChanges),
                                array_map(fn ($c) => $c['old'], $priceChanges)
                            ),
                            array_merge(
                                array_combine(
                                    array_keys($priceChanges),
                                    array_map(fn ($c) => $c['new'], $priceChanges)
                                ),
                                [
                                    'purchase_invoice' => $purchase->invoice_number,
                                    'purchase_id' => $purchase->id,
                                ]
                            ),
                            $purchase->created_by
                        );
                    }

                    // Fire low stock alert if product is now below minimum
                    $product->refresh();
                    if ($product->quantity <= $product->min_stock) {
                        Event::dispatch(new LowStockDetected($product));
                    }
                }
            }

            $purchase->update(['status' => PurchaseStatus::RECEIVED]);
        });
    }

    public function markAsPaid(Purchase $purchase): void
    {
        DB::transaction(function () use ($purchase) {
            // Only RECEIVED purchases can be marked as paid.
            // ORDERED → PAID would record an expense without any stock ever being received,
            // creating phantom finance entries with no corresponding inventory movement.
            if ($purchase->status !== PurchaseStatus::RECEIVED) {
                throw PurchaseException::invalidStatus('pay', $purchase->status->label(), ['id' => $purchase->id]);
            }

            // Strict Validation for Payment
            if (empty($purchase->invoice_number)) {
                throw PurchaseException::missingReference('Invoice Number', ['id' => $purchase->id]);
            }

            if (empty($purchase->proof_image)) {
                throw PurchaseException::missingReference('Proof Image', ['id' => $purchase->id]);
            }

            $purchase->update(['status' => PurchaseStatus::PAID]);

            $this->financeService->recordExpenseFromPurchase($purchase);
            $this->purchaseAccountingService->postPaidPurchase($purchase, (int) ($purchase->created_by ?? auth()->id() ?? 1));
        });
    }

    public function cancelPurchase(Purchase $purchase): void
    {
        DB::transaction(function () use ($purchase) {
            if ($purchase->status === PurchaseStatus::CANCELLED) {
                throw PurchaseException::invalidStatus('cancel', $purchase->status->label(), ['id' => $purchase->id]);
            }

            $wasPaid     = $purchase->status === PurchaseStatus::PAID;
            $wasReceived = $purchase->status === PurchaseStatus::RECEIVED;

            // Restore stock for RECEIVED and PAID purchases (stock was incremented at RECEIVED)
            if ($wasReceived || $wasPaid) {
                $purchase->loadMissing('items');
                $defaultLocationId = $this->stockService->defaultLocationId();

                foreach ($purchase->items as $item) {
                    $product = Product::where('id', $item->product_id)->lockForUpdate()->first();

                    if (!$product) {
                        continue;
                    }

                    $locationId = $item->location_id ?? $defaultLocationId;

                    $this->stockService->decrementAt(
                        $product->id,
                        $locationId,
                        $item->quantity
                    );

                    $product->refresh();

                    $this->auditService->log(
                        'stock.decremento.cancelacion_compra',
                        $product,
                        null,
                        [
                            'quantity_actual'     => $product->quantity,
                            'cantidad_revertida'  => $item->quantity,
                            'location_id'         => $locationId,
                            'purchase_invoice'    => $purchase->invoice_number,
                            'purchase_id'         => $purchase->id,
                        ],
                        $purchase->created_by
                    );
                }
            }

            $purchase->update(['status' => PurchaseStatus::CANCELLED]);

            // Void finance transaction (safe for all statuses — no-op if none exists)
            $this->financeService->voidTransaction($purchase);

            // Reverse the GL journal entry if the purchase was PAID
            if ($wasPaid) {
                $reversal = $this->purchaseAccountingService->reversePurchaseEntry(
                    $purchase,
                    (int) (auth()->id() ?? $purchase->created_by ?? 1)
                );

                if (!$reversal) {
                    throw PurchaseException::cancellationFailed(
                        'No se encontró asiento contable activo para revertir.',
                        ['id' => $purchase->id, 'invoice' => $purchase->invoice_number]
                    );
                }
            }
        });
    }

    public function restoreToDraft(Purchase $purchase): void
    {
        DB::transaction(function () use ($purchase) {
            if ($purchase->status !== PurchaseStatus::CANCELLED) {
                throw PurchaseException::invalidStatus('restore', $purchase->status->label(), ['id' => $purchase->id]);
            }

            $purchase->update(['status' => PurchaseStatus::DRAFT]);
        });
    }

    private function syncItems(Purchase $purchase, array $items): void
    {
        $total = 0;

        foreach ($items as $itemData) {
            $subtotal = $itemData->quantity * $itemData->unit_price;

            PurchaseItem::create([
                'purchase_id' => $purchase->id,
                'product_id' => $itemData->product_id,
                'quantity' => $itemData->quantity,
                'unit_price' => $itemData->unit_price,
                'subtotal'    => $subtotal,
                'selling_price' => $itemData->selling_price,
            ]);

            $total += $subtotal;
        }

        $purchase->update(['total' => $total]);
    }
}
