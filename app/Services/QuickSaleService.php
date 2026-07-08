<?php

namespace App\Services;

use App\DTOs\SaleData;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;

/**
 * Motor de venta/anulación instantánea. Capa fina sobre SaleService: reúne los
 * defaults del "camino rápido" y delega la lógica de dominio (stock + contabilidad)
 * a createSale/cancelSale, que ya son atómicos y reversibles.
 */
class QuickSaleService
{
    public const UNDO_WINDOW_MINUTES = 15;

    public function __construct(private SaleService $sales) {}

    /**
     * Crea una venta completada de un solo producto al instante.
     *
     * @return array{sale: Sale, below_cost: bool}
     */
    public function sell(
        Product $product,
        int $qty,
        ?int $unitPriceCents,
        PaymentMethod $method,
        int $discountCents,
        int $actorId,
        string $source = 'telegram',
    ): array {
        if ($qty <= 0) {
            throw new \RuntimeException('La cantidad debe ser mayor a 0.');
        }
        if ($qty > $product->quantity) {
            throw new \RuntimeException("Stock insuficiente: hay {$product->quantity} disponibles.");
        }
        if ($unitPriceCents !== null && $unitPriceCents < 0) {
            throw new \RuntimeException('El precio no puede ser negativo.');
        }
        $discountCents = max(0, $discountCents);

        // SaleService::createSale ignora items[].unit_price: cotiza con selling_price y
        // resta el `discount` por unidad. Traducimos el precio negociado (más barato) a
        // un descuento por unidad sobre la lista. No permite vender por encima de la lista
        // (para eso se sube el precio del producto) — caso raro, fuera de alcance.
        $listPrice       = $product->selling_price;
        $requestedUnit   = $unitPriceCents ?? $listPrice;
        $perUnitDiscount = max(0, $listPrice - $requestedUnit);
        $effectiveUnit   = $listPrice - $perUnitDiscount; // = min(requested, list)

        $belowCost = $effectiveUnit < $product->purchase_price;
        $lineTotal = $effectiveUnit * $qty;
        $total     = max(0, $lineTotal - $discountCents);

        $saleData = SaleData::fromArray([
            'created_by'      => $actorId,
            'sale_date'       => now()->toDateTimeString(),
            'status'          => SaleStatus::COMPLETED->value,
            'payment_method'  => $method->value,
            'source'          => $source,
            'notes'           => 'Venta rápida',
            'cash_received'   => $total,
            'change'          => 0,
            'global_discount' => $discountCents,
            'customer_id'     => null,
            'items'           => [[
                'product_id' => $product->id,
                'quantity'   => $qty,
                'unit_price' => $listPrice,
                'discount'   => $perUnitDiscount,
            ]],
        ]);

        try {
            $sale = $this->sales->createSale($saleData);
        } catch (\App\Exceptions\SaleException $e) {
            // Normaliza a RuntimeException para que los callers (tools IA) capturen un solo tipo.
            // Cubre p.ej. stock insuficiente a nivel de UBICACIÓN (el pre-check usa el agregado).
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        return ['sale' => $sale, 'below_cost' => $belowCost];
    }

    /**
     * Anula una venta completada aplicando las reglas de permiso/ventana.
     * Reutiliza SaleService::cancelSale (restaura stock + revierte asientos).
     */
    public function void(Sale $sale, User $actor): Sale
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($sale, $actor) {
            // Bloqueo de fila: serializa /deshacer concurrentes (doble-tap) para no
            // restaurar stock ni revertir asientos dos veces. Re-leemos el estado bajo lock.
            $locked = Sale::lockForUpdate()->find($sale->id);
            if (! $locked) {
                throw new \RuntimeException('Venta no encontrada.');
            }

            if ($locked->status !== SaleStatus::COMPLETED) {
                throw new \RuntimeException('Esa venta no se puede deshacer (ya anulada o no completada).');
            }

            if (! $actor->isAdmin()) {
                if ((int) $locked->created_by !== (int) $actor->id) {
                    throw new \RuntimeException('Solo puedes deshacer tus propias ventas.');
                }
                if ($locked->created_at->lt(now()->subMinutes(self::UNDO_WINDOW_MINUTES))) {
                    throw new \RuntimeException('Pasó la ventana de ' . self::UNDO_WINDOW_MINUTES . ' minutos para deshacer esta venta.');
                }
            }

            return $this->sales->cancelSale($locked, 'Deshacer venta rápida');
        });
    }

    /** Anula la última venta completada del actor (o la última global si es admin). */
    public function voidLast(User $actor): Sale
    {
        $query = Sale::where('status', SaleStatus::COMPLETED->value);
        if (! $actor->isAdmin()) {
            $query->where('created_by', $actor->id);
        }

        $sale = $query->latest('id')->first();
        if (! $sale) {
            throw new \RuntimeException('No hay una venta reciente para deshacer.');
        }

        return $this->void($sale, $actor);
    }
}
