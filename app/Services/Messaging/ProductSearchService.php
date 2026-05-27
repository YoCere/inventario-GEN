<?php

namespace App\Services\Messaging;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductSearchService
{
    /** Si true, restringir todas las capas al scope público (Shop module). */
    private bool $publicOnly = false;

    /**
     * Versión pública para el catálogo: filtra is_public=true (vía scope public()
     * del Product, que también respeta shop_show_out_of_stock). Útil para
     * exponer el buscador inteligente en /tienda sin filtrar resultados internos.
     */
    public function searchPublic(string $query): array
    {
        $this->publicOnly = true;
        try {
            return $this->search($query);
        } finally {
            $this->publicOnly = false;
        }
    }

    /**
     * Core layered search. Retorna Collection<Product> sin formatear, en orden
     * de relevancia. Cualquier consumidor (Telegram bot, POS, API, etc.) puede
     * mapear el resultado al shape que necesite.
     *
     * @param string $query
     * @param bool $publicOnly Si true, restringe al scope público (Shop module).
     * @return Collection<int,Product>
     */
    public function searchProducts(string $query, bool $publicOnly = false): Collection
    {
        $previousPublic = $this->publicOnly;
        $this->publicOnly = $publicOnly;

        try {
            // Layer 1: Exact match (SKU o nombre normalizado)
            $results = $this->searchExact($query);
            if (!$results->isEmpty()) {
                return $results;
            }

            // Layer 2: Fuzzy (tokenizado + Levenshtein + accent-insensitive)
            $results = $this->searchFuzzy($query);
            if (!$results->isEmpty()) {
                return $results;
            }

            // Layer 3: AI fallback (si está habilitado y las capas anteriores no encontraron)
            if ($this->isAiEnabled()) {
                $results = $this->searchWithAi($query);
                if (!$results->isEmpty()) {
                    return $results;
                }
            }

            return collect();
        } finally {
            $this->publicOnly = $previousPublic;
        }
    }

    public function search(string $query): array
    {
        // Layer 1: Exact match (SKU or normalized name)
        $results = $this->searchExact($query);
        if (!$results->isEmpty()) {
            return $this->formatResults($results);
        }

        // Layer 2: Fuzzy search (tokenized, case/accent insensitive)
        $results = $this->searchFuzzy($query);
        if (!$results->isEmpty()) {
            return $this->formatResults($results);
        }

        // Layer 3: AI fallback (if enabled and previous layers found nothing)
        if ($this->isAiEnabled()) {
            $results = $this->searchWithAi($query);
            if (!$results->isEmpty()) {
                return $this->formatResults($results);
            }
        }

        return [];
    }

    /**
     * Constrain a query builder to the active scope (public-only vs. internal).
     */
    private function applyScope($query)
    {
        if ($this->publicOnly) {
            // Product::scopePublic() filtra is_public + opcionalmente quantity>0.
            return $query->public();
        }
        return $query->where('is_active', true);
    }

    private function searchExact(string $query): Collection
    {
        return $this->applyScope(Product::query())
            ->where(function ($q) use ($query) {
                $q->where('sku', $query)
                    ->orWhereRaw("LOWER(name) = LOWER(?)", [$query]);
            })
            ->with(['unit', 'category', 'stocks.location.warehouse', 'primaryImage'])
            ->get();
    }

    private function searchFuzzy(string $query): Collection
    {
        $normalized = $this->normalize($query);
        $allTokens = array_values(array_filter(explode(' ', $normalized)));

        if (empty($allTokens)) {
            return collect();
        }

        // Filtrar stop words y tokens muy cortos para evitar que palabras vacías como
        // "de", "y", "con" acumulen puntos al aparecer dentro de nombres de productos.
        // Ej: query "llavero peluche labubu pop mart blanco y azul de conejo" →
        //   token "de" vía str_contains daría +10 a "Ventilador de Pie" — falso positivo.
        $stopWords = [
            // Español
            'y', 'o', 'e', 'de', 'del', 'el', 'la', 'lo', 'los', 'las',
            'un', 'una', 'con', 'en', 'a', 'al', 'por', 'que', 'se',
            'su', 'sus', 'es', 'son', 'no', 'si', 'mas', 'muy', 'ni',
            'para', 'como', 'pero', 'hay', 'son', 'esta', 'esto',
            // Inglés: visión describe en español pero marcas traen "the", "with", "and"
            'the', 'and', 'with', 'for', 'its', 'has', 'are', 'was',
        ];
        $tokens = array_values(array_filter($allTokens, fn($t) =>
            strlen($t) >= 3 && !in_array($t, $stopWords)
        ));
        // Si el filtrado dejó vacío (query era todo stop words), usar tokens con len >= 2
        if (empty($tokens)) {
            $tokens = array_values(array_filter($allTokens, fn($t) => strlen($t) >= 2));
        }
        if (empty($tokens)) {
            return collect();
        }

        // Pre-filtrar en SQL: solo cargar productos cuyo nombre/sku/descripción
        // contenga AL MENOS un token significativo. Evita full-table scan PHP.
        // NOTA: LIKE en SQLite es binary y NO ignora acentos. Usuario que busca "cafe"
        // sin acento puede no matchear "Café" en DB. Para esos casos cae fallback abajo.
        $relations = ['unit', 'category', 'stocks.location.warehouse', 'primaryImage'];

        $products = $this->applyScope(Product::query())
            ->where(function ($q) use ($tokens) {
                foreach ($tokens as $token) {
                    $like = '%' . $token . '%';
                    $q->orWhereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(sku) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(description) LIKE ?', [$like]);
                }
            })
            ->with($relations)
            ->limit(200)
            ->get();

        // Fallback: pre-filter no matcheó (probable mismatch acentos). Cargar set acotado
        // para que el scoring fuzzy PHP (levenshtein) tenga oportunidad. Cap a 500 para
        // evitar OOM. Si tienes >500 productos activos considera columna normalized_name.
        if ($products->isEmpty()) {
            $products = $this->applyScope(Product::query())
                ->with($relations)
                ->limit(500)
                ->get();
        }

        $scored = $products->map(function ($product) use ($tokens, $query) {
            $name_lower = mb_strtolower($product->name);
            $name_normalized = $this->normalize($name_lower);
            $name_words = preg_split('/\s+/', $name_normalized, -1, PREG_SPLIT_NO_EMPTY);
            $desc_lower = mb_strtolower($product->description ?? '');
            $desc_normalized = $this->normalize($desc_lower);

            $score = 0;

            foreach ($tokens as $token) {
                // Word-exact match: busca el token como palabra completa del nombre.
                // Evita que "de" sume puntos porque "ventilador de pie" lo contiene
                // como subcadena en str_contains. Con in_array solo matchea palabras.
                if (in_array($token, $name_words, true)) {
                    $score += 10;
                } elseif (strlen($token) >= 4 && str_contains($name_normalized, $token)) {
                    // Substring bonus solo para tokens de 4+ chars (reduce ruido)
                    $score += 5;
                }

                // Match en descripción (word-exact preferido)
                $desc_words = preg_split('/\s+/', $desc_normalized, -1, PREG_SPLIT_NO_EMPTY);
                if (in_array($token, $desc_words, true)) {
                    $score += 3;
                } elseif (strlen($token) >= 4 && str_contains($desc_lower, $token)) {
                    $score += 1;
                }

                // Typo tolerance: Levenshtein por palabra.
                // Mínimo 4 chars para evitar que tokens cortos ("the", "con", "pie")
                // tengan distancia ≤2 con palabras de 3 chars de cualquier producto.
                foreach ($name_words as $word) {
                    if (strlen($word) >= 4 && strlen($token) >= 4) {
                        $distance = levenshtein($token, $word);
                        if ($distance <= 2) {
                            // Distancia pequeña = match fuzzy
                            $score += (3 - $distance) + 1;
                        }
                    }
                }
            }

            // Bonus: el query completo aparece como subcadena en el nombre (coincidencia total)
            if (stripos($name_lower, $query) !== false) {
                $score += 5;
            }

            return ['product' => $product, 'score' => $score];
        })
        ->filter(fn ($item) => $item['score'] > 0)
        ->sortByDesc('score')
        ->take(5)
        ->pluck('product');

        return $scored;
    }

    private function searchWithAi(string $query): Collection
    {
        // Skip AI for queries that are clearly questions, not product names
        if (str_contains($query, '?') || str_word_count($query) > 3) {
            return collect();
        }

        try {
            $products = $this->applyScope(Product::query())
                ->select(['id', 'name', 'sku'])
                ->limit(50)
                ->get();

            if ($products->isEmpty()) {
                return collect();
            }

            $productList = $products->map(fn ($p) => "{$p->id}:{$p->name} (SKU: {$p->sku})")
                ->implode("\n");

            $prompt = "De esta lista de productos:\n{$productList}\n\n¿Cuál coincide mejor con: '{$query}'?\nResponde SOLO con el ID del producto (número) o 'ninguno' si no hay coincidencia.";

            $provider = \App\Models\Setting::get('ai_provider', 'anthropic');
            $content = $provider === 'openai_compatible'
                ? $this->aiSearchOpenAi($prompt)
                : $this->aiSearchAnthropic($prompt);

            $productId = (int) trim((string) $content);

            if ($productId > 0) {
                $product = $products->firstWhere('id', $productId);
                if ($product) {
                    return collect([$product->load(['unit', 'category', 'stocks.location.warehouse'])]);
                }
            }

            return collect();
        } catch (\Exception $e) {
            Log::error('AI search error', ['error' => $e->getMessage()]);
            return collect();
        }
    }

    private function aiSearchAnthropic(string $prompt): string
    {
        $apiKey = \App\Models\Setting::get('anthropic_api_key', '');
        if (!$apiKey) {
            return '';
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ])->timeout(10)->post('https://api.anthropic.com/v1/messages', [
            'model' => 'claude-haiku-4-5-20251001',
            'max_tokens' => 50,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);

        if ($response->failed()) {
            Log::warning('AI search (Anthropic) failed', ['status' => $response->status()]);
            return '';
        }

        return (string) $response->json('content.0.text', '');
    }

    private function aiSearchOpenAi(string $prompt): string
    {
        $apiKey = \App\Models\Setting::get('openai_api_key', '');
        if (!$apiKey) {
            return '';
        }

        $baseUrl = rtrim(\App\Models\Setting::get('ai_api_base_url', 'https://api.openai.com/v1'), '/');
        $model = \App\Models\Setting::get('ai_model', 'gpt-4o-mini');

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
        ])->timeout(10)->post($baseUrl . '/chat/completions', [
            'model' => $model,
            'max_tokens' => 50,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);

        if ($response->failed()) {
            Log::warning('AI search (OpenAI-compatible) failed', ['status' => $response->status()]);
            return '';
        }

        return (string) $response->json('choices.0.message.content', '');
    }

    private function normalize(string $text): string
    {
        // Convert to lowercase
        $text = mb_strtolower($text, 'UTF-8');

        // Remove accents using iconv (portable, no intl extension needed)
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;

        // Remove special characters, keep only alphanumeric and spaces
        $text = preg_replace('/[^a-z0-9\s]/i', '', $text);

        return trim($text);
    }

    /**
     * Formatea un producto por ID para mostrarlo en el bot (usado por cache de memoria).
     * Retorna array de un elemento compatible con handleSearch, o [] si no existe.
     */
    public function formatProductById(int $productId): array
    {
        $product = $this->applyScope(Product::query())
            ->with(['unit', 'category', 'stocks.location.warehouse', 'primaryImage'])
            ->find($productId);

        if (! $product) {
            return [];
        }

        return $this->formatResults(collect([$product]));
    }

    private function formatResults(Collection $products): array
    {
        return $products->map(function ($product) {
            $unit = $product->unit?->symbol ?? 'uni';
            $price = number_format($product->selling_price / 100, 2);
            $stock = $product->quantity <= $product->min_stock ? '⚠️ ' : '';

            // Build location info. Usa relación eager-loaded si está disponible
            // (ver searchExact/searchFuzzy con ->with('stocks.location.warehouse')).
            // Fallback a query si la relación no está cargada.
            $stocks = $product->relationLoaded('stocks')
                ? $product->stocks->where('quantity', '>', 0)
                : $product->stocks()->with('location.warehouse')->where('quantity', '>', 0)->get();
            $locationLine = '';
            if ($stocks->count() === 1) {
                $loc = $stocks->first()->location;
                $locationLine = "\n📍 Ubicación: " . ($loc?->warehouse?->name ?? '') . ' › ' . ($loc?->name ?? '');
            } elseif ($stocks->count() > 1) {
                $parts = $stocks->map(function ($s) use ($unit) {
                    $loc = $s->location;
                    $whName = $loc?->warehouse?->name ?? '';
                    $locName = $loc?->name ?? '';
                    return "  • {$whName} › {$locName}: {$s->quantity} {$unit}";
                })->implode("\n");
                $locationLine = "\n📍 Ubicaciones:\n{$parts}";
            }

            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'price' => $price,
                'quantity' => $product->quantity,
                'unit' => $unit,
                'min_stock' => $product->min_stock,
                'image_path' => $product->image_path,
                'message' => "📦 <b>{$product->name}</b>\n" .
                    "💰 Precio: {$price}\n" .
                    "📊 Stock: {$stock}{$product->quantity} {$unit}\n" .
                    "SKU: {$product->sku}" .
                    $locationLine,
            ];
        })->values()->toArray();
    }

    /**
     * Re-rankea y filtra resultados de búsqueda visual usando IA.
     *
     * Después de que visión identifica "Llavero peluche Labubu Pop Mart…" y el
     * buscador fuzzy devuelve 5 candidatos (incluyendo Aceite, Ventilador, etc.),
     * la IA examina los nombres y determina cuáles son semánticamente relevantes.
     *
     * Retorna el array filtrado y reordenado (mejor match primero).
     * Si la IA no está habilitada o falla, devuelve los resultados originales.
     *
     * @param string $description Descripción de visión del producto fotografiado.
     * @param array  $results     Array formateado de formatResults() con claves id/name/sku.
     * @return array
     */
    public function rerankForVision(string $description, array $results): array
    {
        if (!$this->isAiEnabled() || count($results) <= 1) {
            return $results;
        }

        try {
            $list = collect($results)
                ->map(fn($r, $i) => ($i + 1) . '. ' . $r['name'])
                ->implode("\n");

            $prompt = <<<PROMPT
Un cliente fotografió un producto. El modelo de visión lo describió como:
"{$description}"

Estos son los productos encontrados en el inventario de la tienda:
{$list}

Tarea: identifica cuáles de estos productos corresponden a la descripción.
Devuelve SOLO los números de los productos relevantes, separados por coma, en orden de relevancia (más probable primero).
Si ninguno corresponde, responde: ninguno

Responde ÚNICAMENTE con números (ej: 5 o 2,5), sin texto adicional.
PROMPT;

            $provider = \App\Models\Setting::get('ai_provider', 'anthropic');
            $content = $provider === 'openai_compatible'
                ? $this->aiSearchOpenAi($prompt)
                : $this->aiSearchAnthropic($prompt);

            $answer = trim((string) $content);

            if (strtolower($answer) === 'ninguno' || $answer === '') {
                return $results;
            }

            // Parsear: "5" o "2,5" o "1, 3, 5"
            $positions = array_filter(
                array_map('intval', preg_split('/[\s,]+/', $answer)),
                fn($n) => $n >= 1 && $n <= count($results)
            );

            if (empty($positions)) {
                return $results;
            }

            // Reordenar: primero los elegidos por IA en su orden, luego descartar el resto
            $reranked = [];
            foreach ($positions as $pos) {
                $reranked[] = $results[$pos - 1];
            }

            Log::info('Vision rerank applied', [
                'description' => $description,
                'original_count' => count($results),
                'reranked_count' => count($reranked),
                'ai_answer' => $answer,
            ]);

            return $reranked;
        } catch (\Exception $e) {
            Log::warning('Vision rerank error', ['error' => $e->getMessage()]);
            return $results;
        }
    }

    private function isAiEnabled(): bool
    {
        return \App\Models\Setting::get('ai_search_enabled') === '1';
    }
}
