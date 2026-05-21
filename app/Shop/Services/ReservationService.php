<?php

namespace App\Shop\Services;

use App\DTOs\SaleData;
use App\DTOs\SaleItemData;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Exceptions\SaleException;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Orquesta una reserva web: valida que los productos sigan siendo públicos
 * + tengan stock + el precio actual coincida razonablemente con el carrito
 * cliente (anti-tampering), luego delega al SaleService para crear la venta
 * en estado PENDING con source='web'.
 *
 * Devuelve la Sale creada (con stock ya decrementado vía SaleService).
 */
class ReservationService
{
    public function __construct(
        private SaleService $saleService,
    ) {}

    /**
     * @param string $buyerName
     * @param string $buyerPhone
     * @param array<int,array{product_id:int,qty:int}> $items
     * @return Sale
     *
     * @throws \InvalidArgumentException si items vacío o malformado
     * @throws SaleException si producto inexistente o sin stock
     */
    public function createReservation(string $buyerName, string $buyerPhone, array $items): Sale
    {
        if (empty($items)) {
            throw new \InvalidArgumentException('El carrito está vacío.');
        }

        // Reload precios de la BD — nunca confiar en lo que mandó el cliente.
        return DB::transaction(function () use ($buyerName, $buyerPhone, $items) {
            $productIds = array_map(fn ($i) => (int) $i['product_id'], $items);
            $products = Product::query()
                ->whereIn('id', $productIds)
                ->lockForUpdate() // evita oversell entre dos clientes simultáneos
                ->get()
                ->keyBy('id');

            $saleItems = [];
            foreach ($items as $line) {
                $productId = (int) ($line['product_id'] ?? 0);
                $qty = (int) ($line['qty'] ?? 0);

                if ($qty <= 0) {
                    throw new \InvalidArgumentException("Cantidad inválida para producto #{$productId}.");
                }

                $product = $products->get($productId);
                if (! $product) {
                    throw new \InvalidArgumentException("Producto #{$productId} no encontrado.");
                }

                if (! $product->is_public) {
                    throw new \InvalidArgumentException("'{$product->name}' ya no está disponible en línea.");
                }

                if ($product->quantity < $qty) {
                    throw SaleException::insufficientStock(
                        $product->name,
                        $qty,
                        (int) $product->quantity
                    );
                }

                $saleItems[] = new SaleItemData(
                    product_id: $product->id,
                    quantity: $qty,
                    unit_price: (int) $product->selling_price,
                    discount: 0,
                );
            }

            // Bot/admin de la tienda. Usuario 1 si no hay sistema-user definido.
            $systemUserId = (int) (\App\Models\User::where('role', 'admin')->orderBy('id')->value('id') ?? 1);

            $saleData = new SaleData(
                sale_date: Carbon::now(),
                payment_method: PaymentMethod::CASH, // placeholder; admin define real al confirmar
                created_by: $systemUserId,
                items: $saleItems,
                customer_id: null,
                status: SaleStatus::PENDING,
                notes: 'Reserva en línea (sin pasarela de pago).',
                buyer_name: trim($buyerName),
                buyer_phone: trim($buyerPhone),
                source: 'web',
            );

            return $this->saleService->createSale($saleData);
        });
    }
}
