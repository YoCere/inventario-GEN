<?php

namespace App\Services\Receipt;

use App\Services\Messaging\ProductSearchService;

class ProductMatcher
{
    /**
     * Confianza mínima (similitud nombre recibo vs nombre catálogo) para tratar
     * un candidato como "casado". Por debajo, va a la lista de revisión manual
     * para que el usuario lo confirme — evita meter matches dudosos al carrito.
     */
    private const MIN_CONFIDENCE = 0.45;

    public function __construct(private ProductSearchService $search) {}

    /**
     * @return array{matched: array<int,array>, unmatched: array<int,array>}
     */
    public function match(ReceiptData $data): array
    {
        $matched = [];
        $unmatched = [];
        $indexByProduct = []; // product_id => posición en $matched (dedup)

        foreach ($data->lines as $line) {
            $hit = $this->search->searchProducts($line->rawName, publicOnly: false)->first();
            $confidence = $hit ? $this->confidence($line->rawName, $hit->name) : 0.0;

            if (! $hit || $confidence < self::MIN_CONFIDENCE) {
                $unmatched[] = [
                    'raw_name'   => $line->rawName,
                    'quantity'   => $line->quantity,
                    'unit_price' => $line->unitPrice,
                ];
                continue;
            }

            // Dedup: dos líneas del recibo que casan con el mismo producto suman
            // cantidad en vez de pisarse (el frontend agrega una sola fila).
            if (isset($indexByProduct[$hit->id])) {
                $matched[$indexByProduct[$hit->id]]['quantity'] += $line->quantity;
                continue;
            }

            $indexByProduct[$hit->id] = count($matched);
            $matched[] = [
                'raw_name'     => $line->rawName,
                'product_id'   => $hit->id,
                'product_name' => $hit->name,
                'product_code' => $hit->sku,
                'quantity'     => $line->quantity,
                'unit_price'   => $line->unitPrice,
                'confidence'   => $confidence,
            ];
        }

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    private function confidence(string $a, string $b): float
    {
        similar_text(mb_strtolower($a), mb_strtolower($b), $percent);
        return round($percent / 100, 2);
    }
}
