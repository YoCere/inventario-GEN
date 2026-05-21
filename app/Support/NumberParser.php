<?php

namespace App\Support;

/**
 * Extract numbers from natural-language input (voice transcripts, free text).
 * Used by bot handlers so users can say "venderemos 40", "cuarenta cajas", "vender 40", etc.
 */
class NumberParser
{
    private const SPANISH_WORDS = [
        'cero' => 0, 'uno' => 1, 'una' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4,
        'cinco' => 5, 'seis' => 6, 'siete' => 7, 'ocho' => 8, 'nueve' => 9, 'diez' => 10,
        'once' => 11, 'doce' => 12, 'trece' => 13, 'catorce' => 14, 'quince' => 15,
        'dieciseis' => 16, 'dieciséis' => 16, 'diecisiete' => 17, 'dieciocho' => 18,
        'diecinueve' => 19, 'veinte' => 20, 'veintiuno' => 21, 'veintidos' => 22,
        'veintidós' => 22, 'veintitres' => 23, 'veintitrés' => 23, 'veinticuatro' => 24,
        'veinticinco' => 25, 'veintiseis' => 26, 'veintiséis' => 26, 'veintisiete' => 27,
        'veintiocho' => 28, 'veintinueve' => 29, 'treinta' => 30, 'cuarenta' => 40,
        'cincuenta' => 50, 'sesenta' => 60, 'setenta' => 70, 'ochenta' => 80,
        'noventa' => 90, 'cien' => 100, 'ciento' => 100, 'doscientos' => 200,
        'trescientos' => 300, 'cuatrocientos' => 400, 'quinientos' => 500,
        'seiscientos' => 600, 'setecientos' => 700, 'ochocientos' => 800,
        'novecientos' => 900, 'mil' => 1000,
    ];

    /**
     * Extract an integer from arbitrary text.
     * - "40" → 40
     * - "venderemos 40" → 40
     * - "cuarenta" → 40
     * - "vender cuarenta cajas" → 40
     * - "cuarenta y dos" → 40 (compound not supported, returns first match)
     * - "no hay número" → null
     */
    public static function extractInt(string $text): ?int
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return null;
        }

        // Digits win — most reliable signal in voice transcripts
        if (preg_match('/-?\d+/', $text, $m)) {
            return (int) $m[0];
        }

        // Word-based fallback (Spanish)
        foreach (self::SPANISH_WORDS as $word => $n) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $text)) {
                return $n;
            }
        }

        return null;
    }

    /**
     * Extract a positive number (int or decimal) from text. For prices.
     * - "1.50" → 1.5
     * - "1,50" → 1.5 (Spanish decimal)
     * - "Bs 25.50" → 25.5
     * - "venta 40 bolivianos" → 40.0
     */
    public static function extractFloat(string $text): ?float
    {
        $text = trim(mb_strtolower($text));
        if ($text === '') {
            return null;
        }

        // Normalize Spanish decimal comma → dot
        $normalized = preg_replace('/(\d),(\d)/', '$1.$2', $text);

        if (preg_match('/-?\d+(?:\.\d+)?/', $normalized, $m)) {
            return (float) $m[0];
        }

        // Fall back to integer-word lookup if no digits
        $int = self::extractInt($text);
        return $int === null ? null : (float) $int;
    }
}
