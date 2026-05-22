<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    public function search(Request $request)
    {
        $query = $request->input('q') ?? $request->input('search');
        $limit = min((int) $request->input('limit', 50), 100);

        $cacheKey = 'products_search_' . md5(($query ?? '') . '|' . $limit);

        $products = Cache::remember($cacheKey, 300, function () use ($query, $limit) {
            return Product::query()
                ->with(['unit', 'primaryImage'])
                ->where('is_active', true)
                ->where('quantity', '>', 0) // Only show available products
                ->when($query, function ($q) use ($query) {
                    $q->where(function ($inner) use ($query) {
                        $inner->where('name', 'like', "%{$query}%")
                              ->orWhere('sku', 'like', "%{$query}%");
                    });
                })
                ->orderByDesc('featured')
                ->orderBy('name')
                ->limit($limit)
                ->get()
                ->map(function ($product) {
                    return [
                        'value' => $product->id,
                        'id' => $product->id,
                        'text' => $product->name,
                        'name' => $product->name,
                        'price' => $product->purchase_price,
                        'selling_price' => $product->selling_price,
                        'sku' => $product->sku,
                        'quantity' => $product->quantity,
                        // URL imagen "card" (~600px WebP si existe galería del Shop module,
                        // sino fallback al image_path legacy, sino placeholder SVG).
                        'image_url' => $product->card_image_url,
                        'featured' => (bool) $product->featured,
                        'unit' => $product->unit ? [
                            'symbol' => $product->unit->symbol,
                            'name' => $product->unit->name
                        ] : null,
                    ];
                });
        });

        return response()->json($products);
    }
}
