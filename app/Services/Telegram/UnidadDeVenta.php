<?php

namespace App\Services\Telegram;

use App\Models\Unit;
use App\Settings\ProductIntakeFields;
use Illuminate\Support\Facades\Log;

/**
 * Cómo se vende el producto: por unidad, por caja, por par.
 *
 * Antes el bot escribía `unit_id => 1` en todo producto cargado por voz, con un
 * TODO al lado. En el catálogo que trae el sistema el id 1 es "Pieza", así que
 * una caja de velas quedaba vendiéndose "por pieza" y la cuenta de stock salía
 * mal desde el primer día. La dueña lo pidió explícito: "cómo se vende: por
 * caja, por unidad, el producto solo".
 *
 * Acá vive la traducción de lo que se dice hablando a una de las unidades que ya
 * existen. Nunca se crean unidades nuevas desde el chat: el catálogo de unidades
 * es cerrado (nombre y símbolo son únicos en la base y el símbolo lo usa la
 * factura), así que inventar una desde un audio mal transcrito ensucia algo que
 * después nadie limpia. Si no se entiende, se ofrecen las que hay.
 */
class UnidadDeVenta
{
    /**
     * Muletillas propias de este paso. "se vende por caja" tiene que llegar a
     * "caja"; sin esto la frase entera se busca tal cual y no coincide con nada.
     */
    private const MULETILLAS = [
        'unidad', 'unidades', 'medida', 'forma', 'vende', 'venden', 'vendo', 'vender',
        'venta', 'cada', 'cobro', 'cobra', 'se', 'sale', 'salen', 'entera', 'entero',
    ];

    /**
     * Cómo nombra la gente cada unidad → nombres que puede tener en la base, en
     * orden de preferencia. Es lo que permite que "por unidad" o "el producto
     * solo" caigan en "Pieza", que es lo que trae el catálogo del sistema.
     *
     * @var array<string, array<int, string>>
     */
    private const SINONIMOS = [
        'unidad'    => ['Unidad', 'Pieza', 'Unidades'],
        'unidades'  => ['Unidad', 'Pieza'],
        'uno'       => ['Unidad', 'Pieza'],
        'solo'      => ['Unidad', 'Pieza'],
        'sola'      => ['Unidad', 'Pieza'],
        'suelto'    => ['Unidad', 'Pieza'],
        'suelta'    => ['Unidad', 'Pieza'],
        'individual' => ['Unidad', 'Pieza'],
        'pieza'     => ['Pieza', 'Unidad'],
        'piezas'    => ['Pieza', 'Unidad'],
        'producto'  => ['Unidad', 'Pieza'],
        'item'      => ['Unidad', 'Pieza'],
        'caja'      => ['Caja'],
        'cajas'     => ['Caja'],
        'cajita'    => ['Caja'],
        'par'       => ['Par'],
        'pares'     => ['Par'],
        'docena'    => ['Docena'],
        'docenas'   => ['Docena'],
        'kilo'      => ['Kilogramo', 'Kilo'],
        'kilos'     => ['Kilogramo', 'Kilo'],
        'kilogramo' => ['Kilogramo'],
        'gramo'     => ['Gramo', 'Kilogramo'],
        'gramos'    => ['Gramo', 'Kilogramo'],
        'litro'     => ['Litro'],
        'litros'    => ['Litro'],
        'metro'     => ['Metro'],
        'metros'    => ['Metro'],
        'bolsa'     => ['Bolsa'],
        'bolsas'    => ['Bolsa'],
        'paquete'   => ['Paquete', 'Bolsa', 'Caja'],
        'paquetes'  => ['Paquete', 'Bolsa', 'Caja'],
        'rollo'     => ['Rollo'],
        'rollos'    => ['Rollo'],
        'pallet'    => ['Pallet'],
        'pallets'   => ['Pallet'],
        'tarima'    => ['Pallet'],
    ];

    /**
     * Cómo suena, en un audio, decir cómo se vende. El verbo primero y la unidad
     * después: es lo que separa "se lo venderá por unidad" del "de 2 metros de
     * largo" que viene en el nombre del mismo producto.
     *
     * `vend\p{L}*` cubre las conjugaciones sin listarlas (vende, venden, vendo,
     * venderá, vender, vendemos), y el artículo intermedio es opcional porque
     * tanto "por unidad" como "por la unidad" se dicen igual de seguido.
     */
    private const DICE_COMO_SE_VENDE = [
        '/\bvend\p{L}*\s+(?:por|en|como|a|de)\s+(?:el|la|los|las|un|una|cada)?\s*(?<unidad>\p{L}+)/iu',
        '/\b(?:viene|vienen|van|va)\s+(?:en|por)\s+(?:el|la|los|las|un|una)?\s*(?<unidad>\p{L}+)/iu',
        '/\b(?:se\s+cobra|cobro|cobra)\s+(?:por|en)\s+(?:el|la|los|las|un|una|cada)?\s*(?<unidad>\p{L}+)/iu',
    ];

    public function __construct(private RespuestaNatural $respuesta) {}

    /**
     * ¿Vale la pena preguntar cómo se vende?
     *
     * No, si la dueña apagó el dato en Ajustes. Y tampoco si el negocio tiene una
     * sola unidad cargada: ahí no hay nada que elegir y la pregunta sería un
     * trámite para alguien que está cargando productos por audio.
     */
    public function hayQueElegir(): bool
    {
        return ProductIntakeFields::pide('unidad') && Unit::count() > 1;
    }

    /** Las unidades que se pueden ofrecer, en el orden en que se muestran. */
    public function disponibles(): \Illuminate\Support\Collection
    {
        return Unit::orderBy('name')->get();
    }

    /**
     * Qué unidad quiso decir, sin gastar una llamada a la IA.
     *
     * Orden: la frase limpia, después palabra por palabra. De cada candidato se
     * prueba nombre exacto, símbolo, sinónimo y singular/plural antes de aceptar
     * un parecido parcial, que es el que más se equivoca.
     */
    public function buscar(string $texto): ?Unit
    {
        $limpio = $this->respuesta->valor($texto, self::MULETILLAS);

        $candidatos = array_merge(
            $limpio !== '' ? [$limpio] : [],
            $this->palabras($limpio),
            // Las palabras tal como se dijeron, al final de la cola. Hacen falta
            // porque "unidad" es a la vez muletilla de este paso ("la unidad es
            // caja") y la respuesta más común del catálogo. Con la limpieza sola,
            // "unidad" y "se vende por unidad" quedaban en nada y el bot volvía a
            // preguntar lo que la persona ya había contestado. Van después de lo
            // limpio para que "la unidad es caja" siga dando Caja y no Unidad.
            $this->palabras($texto),
        );

        if ($candidatos === []) {
            return null;
        }

        foreach ($candidatos as $candidato) {
            $unidad = $this->porNombreOSimbolo($candidato) ?? $this->porSinonimo($candidato);

            if ($unidad) {
                return $unidad;
            }
        }

        // El parecido parcial es el que más se equivoca, así que solo se le dan
        // los candidatos ya limpios: con las palabras crudas, un "por" o un
        // "para" terminaría eligiendo cualquier unidad que los contenga.
        foreach (array_merge($limpio !== '' ? [$limpio] : [], $this->palabras($limpio)) as $candidato) {
            if ($unidad = $this->porParecido($candidato)) {
                return $unidad;
            }
        }

        return null;
    }

    /**
     * Lo que se dictó, cuando dice cómo se vende.
     *
     * Esto NO es `buscar()` sobre el audio entero. El audio trae el nombre del
     * producto, y el nombre dice cosas como "de 2 metros y medio de largo":
     * buscar una palabra de unidad suelta ahí dejaría esa faja vendiéndose por
     * metro. Así que la palabra tiene que venir colgada de un verbo de vender o
     * de venir — "se lo venderá por unidad", "viene en cajas" — y lo que se
     * agarre se verifica igual contra las unidades que existen.
     *
     * Vive acá, y no en el modelo, por lo mismo que "destacado": si lo dijo, el
     * dato no puede quedar colgado de cuánto se acuerde el modelo de extraerlo.
     */
    public function enLoDicho(?string $frase): ?Unit
    {
        if ($frase === null || trim($frase) === '') {
            return null;
        }

        foreach (self::DICE_COMO_SE_VENDE as $patron) {
            if (preg_match($patron, $frase, $coincidencia) !== 1) {
                continue;
            }

            if ($unidad = $this->buscar($coincidencia['unidad'])) {
                return $unidad;
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function palabras(string $texto): array
    {
        return preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * La unidad que se usa cuando el dato no se pregunta.
     *
     * Sale del ajuste que la dueña ve en Ajustes (`ProductIntakeFields`), no de
     * una cuenta ni de un id fijo. Si ese nombre no corresponde a ninguna unidad
     * cargada queda la primera, y se avisa en el log: es una configuración
     * incoherente que alguien tiene que mirar, no algo para resolver en silencio.
     */
    public function porDefecto(): ?Unit
    {
        $configurada = trim((string) ProductIntakeFields::porDefecto('unidad'));

        if ($configurada !== '' && $unidad = $this->buscar($configurada)) {
            return $unidad;
        }

        $primera = Unit::orderBy('id')->first();

        if ($primera && Unit::count() > 1) {
            Log::warning('La unidad por defecto configurada no existe; se usa la primera', [
                'configurada' => $configurada,
                'usada'       => $primera->name,
            ]);
        }

        return $primera;
    }

    private function porNombreOSimbolo(string $valor): ?Unit
    {
        if (mb_strlen($valor) < 1) {
            return null;
        }

        $unidad = Unit::whereRaw('LOWER(name) = LOWER(?)', [$valor])
            ->orWhereRaw('LOWER(symbol) = LOWER(?)', [$valor])
            ->first();

        if ($unidad) {
            return $unidad;
        }

        // "cajas" dicho y "Caja" guardada, o al revés.
        foreach ($this->respuesta->variantes($valor) as $variante) {
            $unidad = Unit::whereRaw('LOWER(name) = LOWER(?)', [$variante])->first();

            if ($unidad) {
                return $unidad;
            }
        }

        return null;
    }

    private function porSinonimo(string $palabra): ?Unit
    {
        $clave = $this->normalizar($palabra);

        if (! isset(self::SINONIMOS[$clave])) {
            return null;
        }

        foreach (self::SINONIMOS[$clave] as $nombre) {
            $unidad = Unit::whereRaw('LOWER(name) = LOWER(?)', [$nombre])->first();

            if ($unidad) {
                return $unidad;
            }
        }

        return null;
    }

    /**
     * Último recurso determinista. Pide tres letras: con menos, "u" o "kg"
     * coincidiría con media docena de nombres y elegiría cualquiera.
     */
    private function porParecido(string $valor): ?Unit
    {
        if (mb_strlen($valor) < 3) {
            return null;
        }

        return Unit::whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($valor) . '%'])
            ->orderBy('name')
            ->first();
    }

    private function normalizar(string $palabra): string
    {
        $palabra = mb_strtolower(trim($palabra));

        $transliterado = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $palabra);
        if ($transliterado !== false && $transliterado !== '') {
            $palabra = preg_replace("/['\"`^~](?=[a-z])/", '', $transliterado) ?? $palabra;
        }

        return preg_replace('/[^a-z0-9]/', '', $palabra) ?? $palabra;
    }
}
