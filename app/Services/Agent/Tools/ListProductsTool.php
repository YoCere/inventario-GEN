<?php

namespace App\Services\Agent\Tools;

use App\Models\Product;
use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;

class ListProductsTool extends BaseTool
{
    public function name(): string
    {
        return 'list_products';
    }

    public function description(): string
    {
        return 'Lista productos del inventario con precio y stock. Filtros opcionales: por nombre de categoría o búsqueda de texto en nombre.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'category' => [
                    'type' => 'string',
                    'description' => 'Nombre de categoría para filtrar (parcial, sin distinción de mayúsculas)',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Texto a buscar en el nombre del producto',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Máximo de resultados (default 10, max 20)',
                ],
            ],
        ];
    }

    public function requiredPermission(): ?string
    {
        return 'products.view';
    }

    public function execute(array $input, AgentContext $context): array
    {
        $limit = max(1, min(20, (int) ($input['limit'] ?? 10)));

        $query = Product::with(['unit', 'category'])->where('is_active', true);

        if (!empty($input['category'])) {
            $query->whereHas('category', function ($q) use ($input) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($input['category']) . '%']);
            });
        }

        if (!empty($input['search'])) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($input['search']) . '%']);
        }

        $products = $query->orderBy('name')->limit($limit)->get();

        if ($products->isEmpty()) {
            return ['found' => 0, 'products' => []];
        }

        return [
            'found' => $products->count(),
            'products' => $products->map(fn ($p) => [
                'id'       => $p->id,
                'name'     => $p->name,
                'sku'      => $p->sku,
                'category' => $p->category?->name,
                'price_bs' => number_format($p->selling_price / 100, 2),
                'stock'    => $p->quantity,
                'unit'     => $p->unit?->symbol ?? 'uni',
                'critical' => $p->quantity <= $p->min_stock,
            ])->toArray(),
        ];
    }
}
