<?php

namespace App\Services\Telegram;

/**
 * Saca el dato de una respuesta hablada.
 *
 * Nadie contesta en formulario. Al "¿Cuál es la categoría?" la gente responde
 * "ponele mates", "la categoría sería mates" o "mates por favor". El bot tomaba
 * la frase entera como si fuera el nombre de la categoría, no la encontraba, y
 * ofrecía crear una categoría llamada "ponele mates".
 *
 * Esto es a propósito determinista: la limpieza corre en CADA respuesta de CADA
 * paso, así que no puede costar una llamada a la IA ni dos segundos de espera.
 */
class RespuestaNatural
{
    /**
     * Arranques que no aportan dato. Si la frase empieza con esto, se descarta
     * palabra por palabra hasta llegar al dato.
     */
    private const ARRANQUE = [
        // muletillas
        'este', 'esto', 'esta', 'bueno', 'mira', 'mire', 'eh', 'ehh', 'mmm', 'am',
        'pues', 'osea', 'ok', 'okey', 'dale', 'ya', 'bien',
        // verbos de asignar
        'ponele', 'ponle', 'pone', 'pon', 'ponerle', 'poner', 'pongamos', 'ponemos',
        'dejale', 'dejalo', 'anotale', 'anota', 'anotar', 'marcale', 'marca',
        // conectores y verbos de ser
        'que', 'sea', 'seria', 'sera', 'es', 'son', 'era', 'va', 'ir', 'ser', 'estaria',
        'y', 'a', 'o',
        // artículos y preposiciones
        'el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas',
        'de', 'del', 'en', 'para', 'por', 'con',
        'mi', 'su', 'le', 'lo', 'me',
    ];

    /** Lo que la gente agrega al final y tampoco es dato. */
    private const CIERRE = [
        'por', 'favor', 'porfa', 'gracias', 'nomas', 'nomass', 'pues', 'ahi', 'ahora',
        'a', 'ver', 'creo', 'seria', 'sera', 'y', 'o', 'listo', 'ok',
    ];

    /**
     * Devuelve solo el dato. Cadena vacía si la respuesta era pura muletilla:
     * ahí el paso tiene que volver a preguntar, NO inventar un valor.
     *
     * @param  array<int, string>  $palabrasDelCampo
     *   Cómo nombra la gente al dato que se está pidiendo ("categoría", "rubro").
     *   Van aparte de la lista general porque dependen del paso: en el paso de la
     *   unidad, "unidad" es muletilla ("la unidad es caja") pero también puede ser
     *   la respuesta entera ("unidad"), y eso lo resuelve quien llama.
     */
    public function valor(string $texto, array $palabrasDelCampo = []): string
    {
        $palabras = preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Se limpia el borde de cada palabra ("categoría," → "categoría") pero se
        // conserva el texto original: el nombre del producto puede llevar tildes,
        // mayúsculas y números que importan.
        $palabras = array_values(array_filter(array_map(
            fn (string $p) => trim($p, " \t\n\r\0\x0B.,;:¿?¡!\"'()"),
            $palabras
        ), fn (string $p) => $p !== ''));

        $arranque = array_merge(self::ARRANQUE, array_map([$this, 'normalizar'], $palabrasDelCampo));
        $cierre = array_merge(self::CIERRE, array_map([$this, 'normalizar'], $palabrasDelCampo));

        // Primero el final: "mates por favor" → "mates".
        while ($palabras !== [] && in_array($this->normalizar(end($palabras)), $cierre, true)) {
            array_pop($palabras);
        }

        // Después el principio: "ponele mates" → "mates".
        while ($palabras !== [] && in_array($this->normalizar($palabras[0]), $arranque, true)) {
            array_shift($palabras);
        }

        return trim(implode(' ', $palabras));
    }

    /**
     * Variantes de singular y plural para buscar lo que ya existe: la dueña dice
     * "mates" y la categoría guardada se llama "Mate" (o al revés).
     *
     * @return array<int, string>
     */
    public function variantes(string $valor): array
    {
        $valor = trim($valor);

        if ($valor === '') {
            return [];
        }

        $variantes = [$valor];

        if (preg_match('/es$/iu', $valor)) {
            $variantes[] = mb_substr($valor, 0, -2);
        }

        if (preg_match('/s$/iu', $valor)) {
            $variantes[] = mb_substr($valor, 0, -1);
        } else {
            $variantes[] = $valor . 's';
            $variantes[] = $valor . 'es';
        }

        return array_values(array_unique(array_filter($variantes, fn ($v) => mb_strlen($v) >= 3)));
    }

    private function normalizar(string $palabra): string
    {
        $palabra = mb_strtolower($palabra);

        $transliterado = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $palabra);
        if ($transliterado !== false && $transliterado !== '') {
            $palabra = preg_replace("/['\"`^~](?=[a-z])/", '', $transliterado) ?? $palabra;
        }

        return preg_replace('/[^a-z0-9]/', '', $palabra) ?? $palabra;
    }
}
