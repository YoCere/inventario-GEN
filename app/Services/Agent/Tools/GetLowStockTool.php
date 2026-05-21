<?php

namespace App\Services\Agent\Tools;

use App\Models\Product;
use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;

class GetLowStockTool extends BaseTool
{
    public function name(): string
    {
        return 'get_low_stock';
    }

    public function description(): string
    {
        return 'Lista todos los productos con stock en nivel crítico (quantity <= min_stock). Útil para saber qué reponer.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function execute(array $input, AgentContext $context): array
    {
        $products = Product::with('unit')
            ->where('is_active', true)
            ->whereRaw('quantity <= min_stock')
            ->orderBy('quantity')
            ->limit(20)
            ->get();

        if ($products->isEmpty()) {
            return ['critical' => 0, 'products' => [], 'message' => 'Todo el inventario tiene stock suficiente.'];
        }

        return [
            'critical' => $products->count(),
            'products' => $products->map(fn ($p) => [
                'id'        => $p->id,
                'name'      => $p->name,
                'sku'       => $p->sku,
                'stock'     => $p->quantity,
                'min_stock' => $p->min_stock,
                'unit'      => $p->unit?->symbol ?? 'uni',
            ])->toArray(),
        ];
    }
}
