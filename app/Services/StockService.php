<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use Illuminate\Support\Facades\DB;

/**
 * Centralizes per-location stock operations.
 *
 * Convention: products.quantity is a DENORMALIZED CACHE of SUM(product_stocks.quantity).
 * After any product_stocks change, callers MUST invoke syncProductQuantity().
 */
class StockService
{
    /**
     * Pick a location for sale (FIFO by stock row id) with enough quantity.
     * Returns the ProductStock row (locked) or null if no single location has enough.
     *
     * NOTE: caller must be inside a DB::transaction with lockForUpdate already in scope.
     */
    public function pickFifoLocationForSale(int $productId, int $quantityNeeded): ?ProductStock
    {
        return ProductStock::where('product_id', $productId)
            ->where('quantity', '>=', $quantityNeeded)
            ->lockForUpdate()
            ->orderBy('id', 'asc')
            ->first();
    }

    /**
     * Get total stock across all locations for a product.
     */
    public function totalStock(int $productId): int
    {
        return (int) ProductStock::where('product_id', $productId)->sum('quantity');
    }

    /**
     * Sync products.quantity from sum of product_stocks.quantity.
     * Call after any product_stocks mutation.
     */
    public function syncProductQuantity(int $productId): void
    {
        $total = ProductStock::where('product_id', $productId)->sum('quantity');
        Product::where('id', $productId)->update(['quantity' => $total]);
    }

    /**
     * Decrement stock at a specific location. Returns the affected ProductStock row.
     * Throws if insufficient.
     */
    public function decrementAt(int $productId, int $locationId, int $quantity): ProductStock
    {
        $stock = ProductStock::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if (!$stock) {
            throw new \RuntimeException(
                "Stock no encontrado para producto {$productId} en ubicación {$locationId}."
            );
        }

        if ($stock->quantity < $quantity) {
            throw new \RuntimeException(
                "Stock insuficiente en ubicación '{$stock->location?->name}': hay {$stock->quantity}, necesitas {$quantity}."
            );
        }

        $stock->decrement('quantity', $quantity);
        $this->syncProductQuantity($productId);

        return $stock;
    }

    /**
     * Increment stock at a specific location. Creates row if missing.
     */
    public function incrementAt(int $productId, int $locationId, int $quantity): ProductStock
    {
        $stock = ProductStock::where('product_id', $productId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if (!$stock) {
            $stock = ProductStock::create([
                'product_id' => $productId,
                'location_id' => $locationId,
                'quantity' => $quantity,
            ]);
        } else {
            $stock->increment('quantity', $quantity);
        }

        $this->syncProductQuantity($productId);

        return $stock;
    }

    /**
     * Get default location ID (must always exist post-FASE 1 migration).
     */
    public function defaultLocationId(): int
    {
        $id = Location::default()?->id;
        if (!$id) {
            throw new \RuntimeException('No default location configured. Seed default warehouse + location first.');
        }
        return $id;
    }
}
