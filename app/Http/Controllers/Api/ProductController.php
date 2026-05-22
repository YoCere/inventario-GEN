<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Services\Messaging\ProductSearchService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    public function __construct(private ProductSearchService $searchService) {}

    /**
     * Endpoint AJAX usado por el POS y otros formularios admin.
     *
     * Búsqueda inteligente:
     *  - query vacío (catálogo inicial)    → primeros N productos (featured DESC, name ASC).
     *  - query >= 2 chars                   → ProductSearchService:
     *      Layer 1: match exacto en SKU/nombre normalizado.
     *      Layer 2: fuzzy tokenizado + Levenshtein (tolera typos, acentos, orden).
     *      Layer 3: fallback IA (Anthropic/OpenAI) si está habilitado en Settings.
     *
     * Devuelve la shape esperada por el POS (Alpine): id, name, sku, selling_price (cents),
     * quantity, unit object, image_url, featured.
     */
    public function search(Request $request)
    {
        $query = trim((string) ($request->input('q') ?? $request->input('search') ?? ''));
        $limit = min((int) $request->input('limit', 50), 100);

        $cacheKey = 'products_search_v2_' . md5($query . '|' . $limit);

        $products = Cache::remember($cacheKey, 60, function () use ($query, $limit) {
            // ── Sin query (catálogo inicial): primeros N productos activos con stock,
            //    ordenados featured DESC → name ASC. No usa ProductSearchService.
            if (mb_strlen($query) < 2) {
                return Product::query()
                    ->with(['unit', 'primaryImage'])
                    ->where('is_active', true)
                    ->where('quantity', '>', 0)
                    ->orderByDesc('featured')
                    ->orderBy('name')
                    ->limit($limit)
                    ->get();
            }

            // ── Con query: usar ProductSearchService (exact → fuzzy → IA).
            //    publicOnly=false → scope interno (todos los is_active), no filtra is_public.
            $results = $this->searchService->searchProducts($query, publicOnly: false);

            // Cap al limit + asegurar las relaciones que el POS necesita están eager loaded.
            return $results
                ->take($limit)
                ->loadMissing(['unit', 'primaryImage']);
        });

        $payload = $products->map(function (Product $product) {
            return [
                'value'         => $product->id, // compat TomSelect
                'id'            => $product->id,
                'text'          => $product->name, // compat TomSelect labelField
                'name'          => $product->name,
                'price'         => $product->purchase_price,
                'selling_price' => $product->selling_price,
                'sku'           => $product->sku,
                'quantity'      => $product->quantity,
                'image_url'     => $product->card_image_url,
                'featured'      => (bool) $product->featured,
                'unit'          => $product->unit ? [
                    'symbol' => $product->unit->symbol,
                    'name'   => $product->unit->name,
                ] : null,
            ];
        })->values();

        return response()->json($payload);
    }
}
