<?php

namespace App\Services;

use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Illuminate\Support\Facades\DB;

class TransferService
{
    public function __construct(
        protected StockService $stockService,
        protected AuditService $auditService,
    ) {}

    /**
     * Create a draft transfer with items.
     * Does NOT move stock yet. Call completeTransfer() to execute.
     *
     * @param array{from_location_id:int,to_location_id:int,notes:?string,items:array<array{product_id:int,quantity:int}>} $data
     */
    public function createTransfer(array $data, int $userId): StockTransfer
    {
        if ($data['from_location_id'] === $data['to_location_id']) {
            throw new \InvalidArgumentException('Origen y destino no pueden ser la misma ubicación.');
        }

        if (empty($data['items'])) {
            throw new \InvalidArgumentException('La transferencia debe tener al menos un producto.');
        }

        return DB::transaction(function () use ($data, $userId) {
            $transfer = StockTransfer::create([
                'reference' => $this->generateReference(),
                'from_location_id' => $data['from_location_id'],
                'to_location_id' => $data['to_location_id'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($data['items'] as $itemData) {
                if (($itemData['quantity'] ?? 0) <= 0) {
                    throw new \InvalidArgumentException('Cantidad debe ser mayor a 0.');
                }
                StockTransferItem::create([
                    'transfer_id' => $transfer->id,
                    'product_id' => $itemData['product_id'],
                    'quantity' => $itemData['quantity'],
                ]);
            }

            $this->auditService->log(
                'transferencia.creada',
                $transfer,
                null,
                [
                    'reference' => $transfer->reference,
                    'from' => $transfer->from_location_id,
                    'to' => $transfer->to_location_id,
                    'items_count' => count($data['items']),
                ],
                $userId
            );

            return $transfer;
        });
    }

    /**
     * Execute the transfer: decrement origin + increment destination.
     */
    public function completeTransfer(StockTransfer $transfer): StockTransfer
    {
        if (!$transfer->isDraft()) {
            throw new \RuntimeException("Solo transferencias en borrador pueden completarse. Estado actual: {$transfer->status}");
        }

        return DB::transaction(function () use ($transfer) {
            $transfer->loadMissing('items.product');

            $movements = [];

            foreach ($transfer->items as $item) {
                // Validate source has enough stock
                $this->stockService->decrementAt(
                    $item->product_id,
                    $transfer->from_location_id,
                    $item->quantity
                );

                $this->stockService->incrementAt(
                    $item->product_id,
                    $transfer->to_location_id,
                    $item->quantity
                );

                $movements[] = [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product?->name,
                    'quantity' => $item->quantity,
                ];
            }

            $transfer->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            $this->auditService->log(
                'transferencia.completada',
                $transfer,
                null,
                [
                    'reference' => $transfer->reference,
                    'from_location_id' => $transfer->from_location_id,
                    'to_location_id' => $transfer->to_location_id,
                    'movimientos' => $movements,
                ]
            );

            return $transfer;
        });
    }

    /**
     * Cancel a draft transfer. Completed transfers cannot be cancelled (use reverse transfer).
     */
    public function cancelTransfer(StockTransfer $transfer, ?string $reason = null): StockTransfer
    {
        if (!$transfer->isDraft()) {
            throw new \RuntimeException("Solo transferencias en borrador pueden cancelarse. Para revertir una completada, crea transferencia inversa.");
        }

        return DB::transaction(function () use ($transfer, $reason) {
            $transfer->update(['status' => 'cancelled']);

            $this->auditService->log(
                'transferencia.cancelada',
                $transfer,
                null,
                [
                    'reference' => $transfer->reference,
                    'motivo' => $reason,
                ]
            );

            return $transfer;
        });
    }

    private function generateReference(): string
    {
        $prefix = 'TRA.' . date('ymd') . '.';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $latest = StockTransfer::where('reference', 'like', $prefix . '%')
                ->orderBy('id', 'desc')
                ->lockForUpdate()
                ->first();

            $lastNumber = $latest ? (int) substr($latest->reference, -4) : 0;
            $candidate = $prefix . str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);

            if (!StockTransfer::where('reference', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('No se pudo generar número de referencia único.');
    }
}
