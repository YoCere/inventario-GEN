<?php

namespace App\Services\Receipt;

use App\Services\Messaging\ProductSearchService;

class ProductMatcher
{
    public function __construct(private ProductSearchService $search) {}

    /**
     * @return array{matched: array<int,array>, unmatched: array<int,array>}
     */
    public function match(ReceiptData $data): array
    {
        $matched = [];
        $unmatched = [];

        foreach ($data->lines as $line) {
            $hit = $this->search->searchProducts($line->rawName, publicOnly: false)->first();

            if ($hit) {
                $matched[] = [
                    'raw_name'     => $line->rawName,
                    'product_id'   => $hit->id,
                    'product_name' => $hit->name,
                    'product_code' => $hit->sku,
                    'quantity'     => $line->quantity,
                    'unit_price'   => $line->unitPrice,
                    'confidence'   => $this->confidence($line->rawName, $hit->name),
                ];
            } else {
                $unmatched[] = [
                    'raw_name'   => $line->rawName,
                    'quantity'   => $line->quantity,
                    'unit_price' => $line->unitPrice,
                ];
            }
        }

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    private function confidence(string $a, string $b): float
    {
        similar_text(mb_strtolower($a), mb_strtolower($b), $percent);
        return round($percent / 100, 2);
    }
}
