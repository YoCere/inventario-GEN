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

    public const EDICION_PRODUCTO = 'edicion_producto';

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

    /**
     * Verbos con los que la gente pide cambiar algo que YA existe.
     *
     * A propósito no se cruzan con los del alta: si una misma palabra abriera
     * las dos cosas, habría que adivinar cuál quiso, y adivinar es justo lo que
     * dejó a la persona atrapada.
     */
    private const VERBOS_EDICION = [
        'editar', 'edita', 'editame', 'editalo', 'editala',
        'modificar', 'modifica', 'modificame', 'modificalo', 'modificala',
        'cambiar', 'cambia', 'cambiame', 'cambiale', 'cambialo', 'cambiala', 'cambiarle',
        'corregir', 'corrige', 'corregime', 'corregilo', 'corregila',
        'actualizar', 'actualiza', 'actualizame', 'actualizalo', 'actualizala',
    ];

    /** Cómo le llama la gente a lo que vende. */
    private const COSAS = [
        'producto', 'productos',
        'articulo', 'articulos',
        'item', 'items',
        'mercaderia', 'mercaderias', 'mercancia', 'mercancias',
    ];

    /**
     * Qué se puede pedir cambiar. Además del producto entero, los datos que la
     * dueña nombra sin decir "producto": "cambiale el precio del mate".
     */
    private const COSAS_EDITABLES = [
        'producto', 'productos',
        'articulo', 'articulos',
        'item', 'items',
        'mercaderia', 'mercaderias', 'mercancia', 'mercancias',
        'precio', 'precios',
        'stock', 'stocks',
        'cantidad', 'cantidades',
        'nombre',
        'categoria',
        'foto', 'fotos',
        'dato', 'datos',
    ];

    /**
     * Palabras que no aportan al dato: se sacan del resto del mensaje.
     *
     * Las genéricas ("producto", "articulo") también salen: en
     * "corregir el nombre del producto" no nombran ninguno en particular, y
     * dejarlas haría buscar un producto llamado justamente "producto".
     */
    private const RELLENO = [
        'un', 'una', 'unos', 'unas', 'el', 'la', 'lo', 'los', 'las',
        'nuevo', 'nueva', 'nuevos', 'nuevas',
        'por', 'favor', 'porfa', 'quiero', 'queria', 'necesito', 'voy', 'a', 'al',
        'de', 'del', 'me', 'mi', 'por_favor',
        'producto', 'productos', 'articulo', 'articulos', 'item', 'items',
        'mercaderia', 'mercaderias', 'mercancia', 'mercancias',
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

        // La edición se mira primero porque el alta reconoce "producto nuevo"
        // suelto: sin este orden, "cambiar el precio del producto nuevo" abría
        // un alta en blanco en vez de ir a corregir el producto.
        $edicion = $this->buscar($normalizado, self::VERBOS_EDICION, self::COSAS_EDITABLES);

        if ($edicion !== null) {
            return [
                'intent' => self::EDICION_PRODUCTO,
                // El dato nombrado ("precio", "categoría") se devuelve junto al
                // resto: en el alta "producto" es relleno, pero acá es
                // justamente QUÉ hay que cambiar, y perderlo obliga a volver a
                // preguntarlo. "cambiale el precio de venta del mate a 300"
                // tiene que llegar como "precio venta mate 300".
                'resto' => trim($this->datoNombrado($edicion[0]) . ' ' . $this->resto($normalizado, $edicion)),
            ];
        }

        // "quiero editar el mate camionero": la persona nombra el producto
        // directamente, sin decir "producto" ni nombrar el dato. Pedirle que
        // diga una palabra mágica para que la entiendan es exactamente lo que
        // queremos sacar del medio.
        $suelto = $this->edicionSinSustantivo($normalizado);

        if ($suelto !== null) {
            return ['intent' => self::EDICION_PRODUCTO, 'resto' => $suelto];
        }

        // "crear un producto", "dar de alta una mercadería", "cargame el articulo"
        $alta = $this->buscar($normalizado, self::VERBOS_ALTA, self::COSAS);

        if ($alta !== null) {
            return [
                'intent' => self::ALTA_PRODUCTO,
                'resto' => $this->resto($normalizado, $alta),
            ];
        }

        // "producto nuevo", "artículo nuevo": el orden al revés.
        $cosas = $this->alternativas(self::COSAS);
        $patronInverso = "/\b(?:{$cosas})\b\s+\b(?:nuevo|nueva|nuevos|nuevas)\b/u";

        if (preg_match($patronInverso, $normalizado, $coincidencia, PREG_OFFSET_CAPTURE)) {
            return [
                'intent' => self::ALTA_PRODUCTO,
                'resto' => $this->resto($normalizado, $coincidencia[0]),
            ];
        }

        return null;
    }

    /**
     * De lo que coincidió, qué dato nombró la persona ("precio", "categoría").
     * Devuelve cadena vacía cuando solo dijo "producto" o "artículo", que no
     * nombran ningún dato en particular.
     */
    private function datoNombrado(string $coincidencia): string
    {
        $genericas = self::COSAS;

        foreach (self::COSAS_EDITABLES as $cosa) {
            if (in_array($cosa, $genericas, true)) {
                continue;
            }

            if (preg_match('/\b' . preg_quote($cosa, '/') . '\b/u', $coincidencia)) {
                return $cosa;
            }
        }

        return '';
    }

    /**
     * "quiero editar el mate camionero", "modificá el sombrero chaqueño": un
     * verbo de corregir al principio de la frase y, detrás, el producto.
     *
     * Se exige que el verbo esté al principio (admitiendo "quiero", "necesito"
     * y parecidos antes) para no confundirse con una búsqueda que apenas
     * mencione la palabra en el medio de una oración.
     *
     * @return string|null lo que viene después del verbo, o null si no aplica
     */
    private function edicionSinSustantivo(string $normalizado): ?string
    {
        $arranques = 'quiero|queria|quisiera|necesito|podes|podrias|puedes|podria|me|hay que|voy a|por favor';
        $verbos = $this->alternativas(self::VERBOS_EDICION);

        if (! preg_match("/^(?:(?:{$arranques})\s+){0,2}(?:{$verbos})\b(.*)$/u", $normalizado, $coincidencia)) {
            return null;
        }

        $resto = trim($coincidencia[1]);

        // Sin nada detrás no hay a qué producto referirse, pero igual es un
        // pedido de corregir: el flujo de edición pregunta cuál.
        if ($resto === '') {
            return '';
        }

        $palabras = array_values(array_filter(
            preg_split('/\s+/', $resto, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            fn (string $palabra) => ! in_array($palabra, self::RELLENO, true)
        ));

        return implode(' ', $palabras);
    }

    /**
     * Busca "<verbo> ... <cosa>" con hasta tres palabras en el medio, que es lo
     * que entra en un pedido hablado normal ("cambiale el precio del mate").
     *
     * @param array<int, string> $verbos
     * @param array<int, string> $cosas
     * @return array{0: string, 1: int}|null texto y posición de lo que coincidió
     */
    private function buscar(string $normalizado, array $verbos, array $cosas): ?array
    {
        $patron = '/\b(?:' . $this->alternativas($verbos) . ')\b(?:\s+\S+){0,3}?\s+\b(?:'
            . $this->alternativas($cosas) . ')\b/u';

        if (! preg_match($patron, $normalizado, $coincidencia, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return $coincidencia[0];
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
