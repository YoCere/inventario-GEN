<?php

namespace App\Services\Agent\Tools;

use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;

class GetSlowSellersTool extends BaseTool
{
    public function name(): string
    {
        return 'get_slow_sellers';
    }

    public function description(): string
    {
        return 'Productos que MENOS se venden en un período (los de peor rotación). Por defecto últimos 30 días, límite 5. Útil para "¿qué casi no se vende?".';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days' => ['type' => 'integer', 'description' => 'Días hacia atrás (default 30)'],
                'limit' => ['type' => 'integer', 'description' => 'Cantidad de resultados (default 5)'],
            ],
        ];
    }

    public function requiredPermission(): ?string
    {
        return 'sales.view';
    }

    public function execute(array $input, AgentContext $context): array
    {
        $days = max(1, min(365, (int) ($input['days'] ?? 30)));
        $limit = max(1, min(50, (int) ($input['limit'] ?? 5)));
        $start = now()->subDays($days);

        $rows = \App\Models\Product::query()
            ->leftJoin('sale_items', 'sale_items.product_id', '=', 'products.id')
            ->leftJoin('sales', function ($join) use ($start) {
                $join->on('sales.id', '=', 'sale_items.sale_id')
                    ->where('sales.status', '=', 'completed')
                    ->where('sales.sale_date', '>=', $start);
            })
            ->where('products.is_active', true)
            ->selectRaw('products.id as product_id, products.name, COALESCE(SUM(CASE WHEN sales.id IS NOT NULL THEN sale_items.quantity ELSE 0 END), 0) as qty')
            ->groupBy('products.id', 'products.name')
            ->orderBy('qty', 'asc')
            ->orderBy('products.id', 'asc')
            ->limit($limit)
            ->get();

        return [
            'days' => $days,
            'slow' => $rows->map(fn ($r) => [
                'product_id' => (int) $r->product_id,
                'name' => $r->name,
                'units_sold' => (int) $r->qty,
            ])->toArray(),
        ];
    }
}
