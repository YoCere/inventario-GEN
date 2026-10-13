<?php

namespace App\Support;

/**
 * Cuenta palabras en castellano de verdad.
 *
 * Existe por un bug que dejaba medio catálogo sin buscar: str_word_count()
 * trabaja byte por byte, así que "chaqueño" le cuenta como dos palabras
 * ("chaque" + "o") y "sombrero chaqueño costura a mano" le daba 6 en vez de 5.
 * El ruteo del bot usa ese número para decidir si el mensaje es una charla o el
 * nombre de un producto, y con la cuenta inflada toda búsqueda con ñ o tilde se
 * iba al asistente y nunca llegaba a la base.
 */
final class Palabras
{
    public static function contar(string $texto): int
    {
        // Todo lo que no sea letra o número separa palabras: así "mate, bombilla"
        // son dos y no una sola pegada por la coma.
        $limpio = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $texto) ?? $texto;

        return count(preg_split('/\s+/u', trim($limpio), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
