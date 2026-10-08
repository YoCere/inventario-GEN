<?php

namespace App\Shop\Http\Controllers;

use App\Shop\ShopSettings;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Messaging\ProductSearchService;
use App\Shop\Models\LandingSection;
use App\Shop\Seo\ShareMetaBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class ShopController extends Controller
{
    public function __construct(
        private ProductSearchService $searchService,
        private ShareMetaBuilder $shareMeta,
    ) {}

    /**
     * Punto de entrada de /tienda. Muestra la landing si está activada
     * (shop_landing_enabled='1', default), o el catálogo si no.
     */
    public function index(Request $request): View
    {
        if (Setting::get('shop_landing_enabled', '1') !== '1') {
            return $this->catalog($request);
        }

        $sections = LandingSection::enabled()->ordered()->get();

        return view('shop.landing', [
            'sections' => $sections,
            'shopCategories' => $this->publicCategories(),
            'shareMeta' => $this->shareMeta->forLanding(),
        ]);
    }

    /**
     * Catálogo con sidebar de filtros (categoría, precio) + ordenamiento.
     * (Antes era el cuerpo de index(); ahora vive en /tienda/catalogo.)
     *
     * Query params soportados (todos opcionales):
     *   category=<id>          Filtra por ID de categoría
     *   min=<int>              Precio mínimo en centavos
     *   max=<int>              Precio máximo en centavos
     *   sort=<key>             price_asc | price_desc | name | newest (default: newest)
     *   page=<int>             Paginación Laravel
     */
    public function catalog(Request $request): View
    {
        $query = Product::query()
            ->public()
            ->with(['primaryImage', 'category', 'unit']);

        // Filtro categoría.
        $categoryId = $request->integer('category');
        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        // Filtro precio. Con precios ocultos se ignora aunque venga en la URL: si no,
        // cualquiera deduce el precio de un producto probando rangos a mano.
        $showPrices = ShopSettings::showPrices();
        $min = $showPrices ? $request->integer('min') : 0;
        $max = $showPrices ? $request->integer('max') : 0;
        if ($min > 0) {
            $query->where('selling_price', '>=', $min * 100); // UI maneja unidades, BD centavos.
        }
        if ($max > 0) {
            $query->where('selling_price', '<=', $max * 100);
        }

        // Ordenamiento. Ordenar por precio también lo revela, así que con precios
        // ocultos esas dos opciones caen al orden por defecto.
        $sort = $request->input('sort', 'newest');
        if (! $showPrices && in_array($sort, ['price_asc', 'price_desc'], true)) {
            $sort = 'newest';
        }
        match ($sort) {
            'price_asc'  => $query->orderBy('selling_price', 'asc'),
            'price_desc' => $query->orderBy('selling_price', 'desc'),
            'name'       => $query->orderBy('name', 'asc'),
            default      => $query->orderByDesc('featured')->orderByDesc('id'),
        };

        $products = $query->paginate(24)->withQueryString();

        // Rango precios para slider del filtro.
        $priceRange = Cache::remember('shop.price_range', 300, function () {
            $min = Product::query()->public()->min('selling_price') ?? 0;
            $max = Product::query()->public()->max('selling_price') ?? 0;
            return [
                'min' => (int) floor($min / 100),
                'max' => (int) ceil($max / 100),
            ];
        });

        return view('shop.index', [
            'products' => $products,
            'categories' => $this->publicCategories(),
            'priceRange' => $priceRange,
            'selectedCategory' => $categoryId,
            'selectedMin' => $min,
            'selectedMax' => $max,
            'selectedSort' => $request->input('sort', 'newest'),
            'searchQuery' => $request->input('q'),
            'shareMeta' => $this->shareMeta->forCatalog(),
        ]);
    }

    /**
     * Categorías con al menos 1 producto público (cacheado 5 min).
     * Compartido entre el catálogo (sidebar) y la landing (sección "qué vendemos").
     */
    private function publicCategories()
    {
        return Cache::remember('shop.categories_with_public_products', 300, function () {
            return Category::query()
                ->whereHas('products', fn ($q) => $q->public())
                ->withCount(['products as public_products_count' => fn ($q) => $q->public()])
                ->orderBy('name')
                ->get();
        });
    }

    /**
     * Detalle de un producto público.
     */
    public function show(string $slug): View
    {
        $product = Product::query()
            ->public()
            ->where('slug', $slug)
            ->with(['images', 'primaryImage', 'category', 'unit'])
            ->firstOrFail();

        $related = Product::query()
            ->public()
            ->where('id', '!=', $product->id)
            ->where('category_id', $product->category_id)
            ->with('primaryImage')
            ->limit(4)
            ->get();

        return view('shop.product', [
            'product' => $product,
            'related' => $related,
            'shareMeta' => $this->shareMeta->forProduct($product),
        ]);
    }

    /**
     * Endpoint JSON del buscador inteligente. Reusa ProductSearchService con scope
     * público (layer 1 exact → layer 2 fuzzy levenshtein → layer 3 AI si activado).
     *
     * Respuesta cacheada 60s por query para reducir latencia y carga.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        if ($q === '' || mb_strlen($q) < 2) {
            return response()->json(['results' => [], 'query' => $q]);
        }

        // El estado del interruptor entra en la clave: si no, al apagar los precios el
        // buscador seguiría sirviéndolos desde el cache hasta un minuto después.
        $showPrices = ShopSettings::showPrices();
        $cacheKey = 'shop.search.' . md5(mb_strtolower($q)) . ($showPrices ? '' : '.sin-precios');
        $results = Cache::remember($cacheKey, 60, function () use ($q, $showPrices) {
            $raw = $this->searchService->searchPublic($q);

            // Re-shape para frontend: precio en unidades + url detalle + imagen.
            return collect($raw)->map(function ($item) use ($showPrices) {
                $product = Product::with('primaryImage')->find($item['id']);
                if (! $product) return null;
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'sku' => $product->sku,
                    // Sin precios, el buscador tampoco los devuelve: el JSON es público.
                    'price' => $showPrices ? number_format($product->selling_price / 100, 2) : null,
                    'price_cents' => $showPrices ? $product->selling_price : null,
                    'image' => $product->card_image_url,
                    'url' => route('shop.product', $product->slug),
                ];
            })->filter()->values();
        });

        return response()->json([
            'query' => $q,
            'results' => $results,
            'count' => count($results),
        ]);
    }
}
