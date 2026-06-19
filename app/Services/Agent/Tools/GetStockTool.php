<?php

namespace App\Services\Agent\Tools;

use App\Models\Product;
use App\Models\ProductStock;
use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;

class GetStockTool extends BaseTool
{
    public function name(): string
    {
        return 'get_stock';
    }

    public function description(): string
    {
        return 'Obtiene el stock detallado por ubicación para un producto específico (por id).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_id' => ['type' => 'integer', 'description' => 'ID del producto'],
            ],
            'required' => ['product_id'],
        ];
    }

    public function requiredPermission(): ?string
    {
        return 'products.view';
    }

    public function execute(array $input, AgentContext $context): array
    {
        $product = Product::find($input['product_id'] ?? 0);
        if (!$product) {
            return ['error' => 'Producto no encontrado.'];
        }

        $stocks = ProductStock::with('location.warehouse')
            ->where('product_id', $product->id)
            ->get();

        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'total_stock' => $product->quantity,
            'min_stock' => $product->min_stock,
            'critical' => $product->quantity <= $product->min_stock,
            'locations' => $stocks->map(fn ($s) => [
                'warehouse' => $s->location?->warehouse?->name,
                'location' => $s->location?->name,
                'quantity' => $s->quantity,
            ])->values()->toArray(),
        ];
    }
}
