<?php

namespace App\Services\Telegram;

/**
 * Qué quiere hacer la persona, leído del propio mensaje.
 *
 * Existe porque el ruteo del bot era puramente estadístico: si el mensaje no
 * tenía signo de pregunta y era corto, se asumía que era el nombre de un
 * producto y se iba a buscar. Entonces "crear un producto" terminaba en una
 * búsqueda difusa que a veces hasta encontraba algo parecido y mostraba una
 * ficha sin relación con lo pedido.
 *
 * A propósito es determinista y sin IA: una instancia recién instalada no tiene
 * claves de IA cargadas, y aun así la dueña tiene que poder dar de alta un
 * producto hablándole normal.
 */
class IntentDetector
{
    public const ALTA_PRODUCTO = 'alta_producto';

    /**
     * Verbos con los que la gente pide dar de alta algo. Deliberadamente NO
     * están "poner", "sumar" ni "meter": esos se usan también para stock
     * ("sumá 10 al producto X") y abrirían un alta cuando nadie la pidió.
     */
    private const VERBOS_ALTA = [
        'crear', 'crea', 'creame', 'crealo', 'creo',
        'registrar', 'registra', 'registrame', 'registro',
        'agregar', 'agrega', 'agregame',
        'anadir', 'anade', 'anadi',
        'cargar', 'carga', 'cargame',
        'ingresar', 'ingresa',
        'anotar', 'anota',
        'grabar', 'graba',
        'dar de alta', 'damos de alta', 'doy de alta', 'alta de',
        'nuevo', 'nueva',
    ];

    /** Cómo le llama la gente a lo que vende. */
    private const COSAS = [
        'producto', 'productos',
        'articulo', 'articulos',
        'item', 'items',
        'mercaderia', 'mercaderias', 'mercancia', 'mercancias',
    ];

    /** Palabras que no aportan al dato: se sacan del resto del mensaje. */
    private const RELLENO = [
        'un', 'una', 'unos', 'unas', 'el', 'la', 'los', 'las',
        'nuevo', 'nueva', 'nuevos', 'nuevas',
        'por', 'favor', 'porfa', 'quiero', 'queria', 'necesito', 'voy', 'a',
        'de', 'del', 'me', 'mi', 'por_favor',
    ];

    /**
     * Devuelve la intención y lo que quedó del mensaje después de sacarla, que
     * es donde suelen venir los datos ("crear producto mate imperial 250 350").
     *
     * @return array{intent: string, resto: string}|null
     */
    public function detect(string $texto): ?array
    {
        $normalizado = $this->normalizar($texto);

        if ($normalizado === '') {
            return null;
        }

        $verbos = $this->alternativas(self::VERBOS_ALTA);
        $cosas  = $this->alternativas(self::COSAS);

        // "crear un producto", "dar de alta una mercadería", "cargame el articulo"
        $patron = "/\b(?:{$verbos})\b(?:\s+\S+){0,3}?\s+\b(?:{$cosas})\b/u";

        if (preg_match($patron, $normalizado, $coincidencia, PREG_OFFSET_CAPTURE)) {
            return [
                'intent' => self::ALTA_PRODUCTO,
                'resto' => $this->resto($normalizado, $coincidencia[0]),
            ];
        }

        // "producto nuevo", "artículo nuevo": el orden al revés.
        $patronInverso = "/\b(?:{$cosas})\b\s+\b(?:nuevo|nueva|nuevos|nuevas)\b/u";

        if (preg_match($patronInverso, $normalizado, $coincidencia, PREG_OFFSET_CAPTURE)) {
            return [
                'intent' => self::ALTA_PRODUCTO,
                'resto' => $this->resto($normalizado, $coincidencia[0]),
            ];
        }

        return null;
    }

    /** @param array{0: string, 1: int} $coincidencia texto y posición del match */
    private function resto(string $normalizado, array $coincidencia): string
    {
        [$encontrado, $posicion] = $coincidencia;

        $sinIntencion = substr($normalizado, 0, $posicion) . ' ' . substr($normalizado, $posicion + strlen($encontrado));

        $palabras = array_filter(
            preg_split('/\s+/', trim($sinIntencion), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            fn (string $palabra) => ! in_array($palabra, self::RELLENO, true)
        );

        return trim(implode(' ', $palabras));
    }

    /** Minúsculas, sin tildes y sin signos: "¿Creamé un artículo!" → "creame un articulo". */
    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));

        $transliterado = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
        if ($transliterado !== false && $transliterado !== '') {
            // Algunas plataformas devuelven "a'" o "~n" al transliterar; limpiamos esos restos.
            $texto = preg_replace("/['\"`^~](?=[a-z])/", '', $transliterado) ?? $texto;
        }

        $texto = preg_replace('/[^a-z0-9\s]/', ' ', $texto) ?? $texto;

        return trim(preg_replace('/\s+/', ' ', $texto) ?? $texto);
    }

    /** @param array<int, string> $palabras */
    private function alternativas(array $palabras): string
    {
        // Las más largas primero para que "dar de alta" gane sobre "alta de".
        usort($palabras, fn ($a, $b) => strlen($b) <=> strlen($a));

        return implode('|', array_map(fn (string $p) => preg_quote($p, '/'), $palabras));
    }
}
