<?php

namespace App\Services\Agent\Tools;

use App\Enums\PaymentMethod;
use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;
use App\Services\Messaging\ProductSearchService;
use App\Services\QuickSaleService;

class SellProductTool extends BaseTool
{
    public function __construct(
        private ProductSearchService $search,
        private QuickSaleService $quickSale,
    ) {}

    public function webExposed(): bool
    {
        return false; // Nunca accesible desde el asistente web.
    }

    public function name(): string
    {
        return 'sell_product';
    }

    public function description(): string
    {
        return 'Registra una venta al instante. Interpreta la orden del vendedor. '
            . 'Reglas de precio: "a X bs" = precio POR UNIDAD → usa unit_price; '
            . '"en total X" = precio total del renglón → usa total_price; sin precio → precio de lista. '
            . 'payment_method: cash (contado, por defecto) o transfer. '
            . 'Si el producto es ambiguo, primero usa search_products y pregunta cuál.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product'        => ['type' => 'string', 'description' => 'Nombre o SKU del producto'],
                'quantity'       => ['type' => 'integer', 'description' => 'Cantidad (por defecto 1)'],
                'unit_price'     => ['type' => 'number', 'description' => 'Precio por unidad en Bs (opcional, override)'],
                'total_price'    => ['type' => 'number', 'description' => 'Precio total del renglón en Bs (opcional, alternativo a unit_price)'],
                'payment_method' => ['type' => 'string', 'enum' => ['cash', 'transfer'], 'description' => 'Método de pago'],
                'discount'       => ['type' => 'number', 'description' => 'Descuento en Bs sobre el total (opcional)'],
            ],
            'required' => ['product'],
        ];
    }

    public function execute(array $input, AgentContext $context): array
    {
        if (! $context->user) {
            return ['error' => 'No hay usuario autenticado.'];
        }

        $query = trim((string) ($input['product'] ?? ''));
        if ($query === '') {
            return ['error' => 'Falta el nombre del producto.'];
        }

        $matches = $this->search->searchProducts($query, publicOnly: false);
        if ($matches->isEmpty()) {
            return ['error' => "No encontré ningún producto para \"{$query}\"."];
        }
        if ($matches->count() > 1) {
            return [
                'needs_selection' => true,
                'message'         => 'Hay varios productos que coinciden. Pregunta al usuario cuál.',
                'options'         => $matches->take(6)->map(fn ($p) => [
                    'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku,
                    'price' => number_format($p->selling_price / 100, 2),
                ])->values()->toArray(),
            ];
        }

        $product = $matches->first();
        $qty = max(1, (int) ($input['quantity'] ?? 1));

        // El schema es solo orientativo (no se valida en runtime). Rechazar valores
        // no numéricos evita ventas a Bs 0.00 por casteo silencioso de basura del LLM.
        foreach (['unit_price', 'total_price', 'discount'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== null && $input[$field] !== ''
                && ! is_numeric($input[$field])) {
                return ['error' => "El valor de {$field} no es un número válido."];
            }
        }

        $hasUnit  = isset($input['unit_price'])  && is_numeric($input['unit_price']);
        $hasTotal = isset($input['total_price']) && is_numeric($input['total_price']);
        if ($hasUnit && $hasTotal) {
            return ['error' => 'Especifica solo el precio por unidad o el total del renglón, no ambos.'];
        }

        $unitPriceCents = null;
        if ($hasUnit) {
            $unitPriceCents = (int) round(((float) $input['unit_price']) * 100);
        } elseif ($hasTotal) {
            $unitPriceCents = (int) round(((float) $input['total_price']) * 100 / $qty);
        }

        $discountCents = isset($input['discount']) ? (int) round(((float) $input['discount']) * 100) : 0;
        $methodRaw = strtolower(trim((string) ($input['payment_method'] ?? 'cash')));
        $method = $methodRaw === 'transfer' ? PaymentMethod::TRANSFER : PaymentMethod::CASH;

        try {
            $result = $this->quickSale->sell($product, $qty, $unitPriceCents, $method, $discountCents, $context->user->id);
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }

        $sale = $result['sale'];

        return [
            'ok'           => true,
            'sale_id'      => $sale->id,
            'invoice'      => $sale->invoice_number,
            'product'      => $product->name,
            'quantity'     => $qty,
            'total_bs'     => number_format($sale->total / 100, 2),
            'payment'      => $method->value,
            'below_cost'   => $result['below_cost'],
            'instructions' => 'Confirma la venta al usuario con el desglose. '
                . ($result['below_cost'] ? 'Advierte ⚠️ que se vendió por debajo del costo. ' : '')
                . 'Recuérdale que puede escribir /deshacer para anular.',
        ];
    }
}
