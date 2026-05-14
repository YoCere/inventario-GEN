<?php

namespace App\Services\Messaging;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductSearchService
{
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

    private function searchExact(string $query): Collection
    {
        return Product::where('is_active', true)
            ->where(function ($q) use ($query) {
                $q->where('sku', $query)
                    ->orWhereRaw("LOWER(name) = LOWER(?)", [$query]);
            })
            ->get();
    }

    private function searchFuzzy(string $query): Collection
    {
        $normalized = $this->normalize($query);
        $tokens = array_filter(explode(' ', $normalized));

        if (empty($tokens)) {
            return collect();
        }

        $products = Product::where('is_active', true)->get();

        $scored = $products->map(function ($product) use ($tokens, $query, $normalized) {
            $name_lower = mb_strtolower($product->name);
            $name_normalized = $this->normalize($name_lower);
            $name_words = preg_split('/\s+/', $name_normalized, -1, PREG_SPLIT_NO_EMPTY);
            $desc_lower = mb_strtolower($product->description ?? '');

            $score = 0;

            foreach ($tokens as $token) {
                // Exact match en nombre normalizado
                if (str_contains($name_normalized, $token)) {
                    $score += 10;
                }

                // Match en descripción
                if (str_contains($desc_lower, $token)) {
                    $score += 3;
                }

                // Typo tolerance: Levenshtein por palabra
                foreach ($name_words as $word) {
                    if (strlen($word) >= 3 && strlen($token) >= 3) {
                        $distance = levenshtein($token, $word);
                        if ($distance <= 2) {
                            // Distancia pequeña = match fuzzy
                            $score += (3 - $distance) + 1;
                        }
                    }
                }
            }

            // Bonus: substring match case-insensitive
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
        try {
            $products = Product::where('is_active', true)
                ->select(['id', 'name', 'sku'])
                ->limit(50)
                ->get();

            if ($products->isEmpty()) {
                return collect();
            }

            $productList = $products->map(fn ($p) => "{$p->id}:{$p->name} (SKU: {$p->sku})")
                ->implode("\n");

            $apiKey = \App\Models\Setting::get('anthropic_api_key');
            if (!$apiKey) {
                return collect();
            }

            $prompt = "De esta lista de productos:\n{$productList}\n\n¿Cuál coincide mejor con: '{$query}'?\nResponde SOLO con el ID del producto (número) o 'ninguno' si no hay coincidencia.";

            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout(10)->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-haiku-4-5-20251001',
                'max_tokens' => 50,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

            if ($response->failed()) {
                Log::warning('AI search API failed', ['status' => $response->status()]);
                return collect();
            }

            $content = $response->json('content.0.text', '');
            $productId = (int) trim($content);

            if ($productId > 0) {
                $product = $products->firstWhere('id', $productId);
                if ($product) {
                    return collect([$product->load('unit', 'category')]);
                }
            }

            return collect();
        } catch (\Exception $e) {
            Log::error('AI search error', ['error' => $e->getMessage()]);
            return collect();
        }
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

    private function formatResults(Collection $products): array
    {
        return $products->map(function ($product) {
            $unit = $product->unit?->symbol ?? 'uni';
            $price = number_format($product->selling_price / 100, 2);
            $stock = $product->quantity <= $product->min_stock ? '⚠️ ' : '';

            // Build location info
            $stocks = $product->stocks()->with('location.warehouse')->where('quantity', '>', 0)->get();
            $locationLine = '';
            if ($stocks->count() === 1) {
                $loc = $stocks->first()->location;
                $locationLine = "\n📍 Ubicación: " . ($loc?->warehouse?->name ?? '') . ' › ' . ($loc?->name ?? '');
            } elseif ($stocks->count() > 1) {
                $parts = $stocks->map(function ($s) use ($unit) {
                    $loc = $s->location;
                    $whName = $loc?->warehouse?->name ?? '';
                    return "  • {$whName} › {$loc?->name}: {$s->quantity} {$unit}";
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

    private function isAiEnabled(): bool
    {
        return \App\Models\Setting::get('ai_search_enabled') === '1';
    }
}
