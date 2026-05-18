<?php

namespace App\Services\Agent\Tools;

use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;
use App\Services\Messaging\ProductSearchService;

class SearchProductsTool extends BaseTool
{
    public function __construct(private ProductSearchService $search) {}

    public function name(): string
    {
        return 'search_products';
    }

    public function description(): string
    {
        return 'Busca productos por nombre o SKU. Devuelve hasta 5 productos con id, nombre, precio, stock y ubicaciones.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Término de búsqueda (nombre o SKU del producto)'],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $input, AgentContext $context): array
    {
        $results = $this->search->search($input['query'] ?? '');

        if (empty($results)) {
            return ['found' => 0, 'products' => []];
        }

        // Trim payload sent to LLM
        return [
            'found' => count($results),
            'products' => array_map(fn ($r) => [
                'id' => $r['id'],
                'name' => $r['name'],
                'sku' => $r['sku'],
                'price_bs' => $r['price'],
                'stock' => $r['quantity'],
                'unit' => $r['unit'],
            ], $results),
        ];
    }
}
