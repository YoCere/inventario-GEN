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

        $sale = $this->sales->createSale($saleData);

        return ['sale' => $sale, 'below_cost' => $belowCost];
    }
}
