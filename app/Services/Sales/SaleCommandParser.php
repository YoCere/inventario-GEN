<?php

namespace App\Services\Sales;

use App\DTOs\ParsedSaleCommand;
use App\Enums\PaymentMethod;
use App\Support\NumberParser;

/**
 * Parser determinista de órdenes de venta habladas/escritas. Sin dependencias de DB.
 * Gramática (el precio va al final):
 *   [vende|véndeme|véndele|véndenos|vender|vendan]
 *     ( [cant?] <nombre producto> | [cant?] (del|el|número) <ordinal|N> )
 *     ( a|por|cada [uno] <precio> [bs] | en total <precio> )? [por transferencia]?
 */
class SaleCommandParser
{
    private const SELL_VERB = '/^\s*(?:v[eé]nde(?:me|le|nos)?|vender|vendan)\b/u';

    private const ORDINALS = [
        'primero' => 1, 'primera' => 1, 'segundo' => 2, 'segunda' => 2,
        'tercero' => 3, 'tercera' => 3, 'cuarto' => 4, 'cuarta' => 4,
        'quinto' => 5, 'quinta' => 5, 'sexto' => 6, 'séptimo' => 7, 'septimo' => 7,
        'octavo' => 8, 'noveno' => 9, 'décimo' => 10, 'decimo' => 10,
    ];

    // NO incluir "de": es parte de nombres reales ("figura de mario"); el fuzzy la tolera.
    private const NOISE = ['unidad', 'unidades', 'pieza', 'piezas', 'uni', 'pza', 'pzas'];

    public function parse(string $text): ?ParsedSaleCommand
    {
        $t = mb_strtolower(trim($text));
        if ($t === '' || ! preg_match(self::SELL_VERB, $t)) {
            return null;
        }

        $t = trim((string) preg_replace(self::SELL_VERB, '', $t, 1));

        $method = PaymentMethod::CASH;
        if (preg_match('/\btransfer(?:encia)?\b/u', $t)) {
            $method = PaymentMethod::TRANSFER;
            $t = trim((string) preg_replace('/\b(?:por\s+)?transfer(?:encia)?\b/u', '', $t));
        }

        [$t, $unitPriceCents, $totalPriceCents] = $this->extractPrice($t);
        [$quantity, $productQuery, $position] = $this->extractQtyAndTarget($t);

        if ($productQuery === null && $position === null) {
            return null;
        }

        return new ParsedSaleCommand(
            quantity: $quantity,
            unitPriceCents: $unitPriceCents,
            totalPriceCents: $totalPriceCents,
            method: $method,
            productQuery: $productQuery,
            position: $position,
        );
    }

    /** @return array{0:string,1:?int,2:?int} */
    private function extractPrice(string $t): array
    {
        if (preg_match('/^(.*)\ben\s+total\b(.*)$/u', $t, $m)) {
            $price = NumberParser::extractFloat($m[2]);
            if ($price !== null) {
                return [trim($m[1]), null, (int) round($price * 100)];
            }
        }
        if (preg_match('/^(.*)\b(?:a|por|cada(?:\s+un[oa])?)\s+(?:bs\.?\s*)?([\p{L}\d][\p{L}\d.,]*)\s*(?:bs|bolivianos)?\s*$/u', $t, $m)) {
            $price = NumberParser::extractFloat($m[2]);
            if ($price !== null) {
                return [trim($m[1]), (int) round($price * 100), null];
            }
        }
        return [$t, null, null];
    }

    /** @return array{0:int,1:?string,2:?int} */
    private function extractQtyAndTarget(string $head): array
    {
        $head = trim($head);
        $ordinalAlt = implode('|', array_keys(self::ORDINALS));

        $position = null;
        if (preg_match('/\bdel?\s+(?:n[uú]mero\s+|#\s*)?(' . $ordinalAlt . '|\d+)\b/u', $head, $m)
            || preg_match('/\bn[uú]mero\s+(\d+)\b/u', $head, $m)
            || preg_match('/^\s*(?:el|la)\s+(' . $ordinalAlt . '|\d+)\s*$/u', $head, $m)) {
            $token = $m[1];
            $position = ctype_digit($token) ? (int) $token : (self::ORDINALS[$token] ?? null);
        }

        $qty = NumberParser::extractInt($head);
        if ($position !== null
            && ! preg_match('/^\s*(\d+|[a-záéíóú]+)\s+del?\b/u', $head)
            && ! preg_match('/^\s*(\d+|[a-záéíóú]+)\s+n[uú]mero\b/u', $head)) {
            $qty = 1;
        }
        $quantity = ($qty === null || $qty < 1) ? 1 : $qty;

        if ($position !== null) {
            return [$quantity, null, $position];
        }

        $name = (string) preg_replace('/\b\d+\b/u', '', $head, 1);
        if ($qty !== null && ! preg_match('/\d/', $head)) {
            foreach (NumberParser::spanishWords() as $word) {
                if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $name)) {
                    $name = (string) preg_replace('/\b' . preg_quote($word, '/') . '\b/u', '', $name, 1);
                    break;
                }
            }
        }
        foreach (self::NOISE as $noise) {
            $name = (string) preg_replace('/\b' . preg_quote($noise, '/') . '\b/u', '', $name);
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return [$quantity, $name === '' ? null : $name, null];
    }
}
