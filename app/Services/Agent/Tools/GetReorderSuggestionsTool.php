<?php

namespace App\Services\Agent\Tools;

use App\Models\Product;
use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;

class GetReorderSuggestionsTool extends BaseTool
{
    public function name(): string
    {
        return 'get_reorder_suggestions';
    }

    public function description(): string
    {
        return 'Sugerencias de compra: productos en o por debajo de su stock mínimo, con cantidad sugerida a reponer. Útil para "¿qué nos hace falta comprar?".';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function requiredPermission(): ?string
    {
        return 'products.view';
    }

    public function execute(array $input, AgentContext $context): array
    {
        $products = Product::with('unit')
            ->where('is_active', true)
            ->whereRaw('quantity <= min_stock')
            ->orderByRaw('(min_stock - quantity) desc')
            ->limit(30)
            ->get();

        if ($products->isEmpty()) {
            return ['count' => 0, 'suggestions' => [], 'message' => 'No hay productos que requieran reposición.'];
        }

        return [
            'count' => $products->count(),
            'suggestions' => $products->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'stock' => $p->quantity,
                'min_stock' => $p->min_stock,
                'suggested_qty' => max(0, $p->min_stock - $p->quantity),
                'unit' => $p->unit?->symbol ?? 'uni',
            ])->toArray(),
        ];
    }
}
