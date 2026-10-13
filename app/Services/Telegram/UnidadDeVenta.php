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

        if ($limpio === '') {
            return null;
        }

        $candidatos = array_merge(
            [$limpio],
            preg_split('/\s+/u', $limpio, -1, PREG_SPLIT_NO_EMPTY) ?: []
        );

        foreach ($candidatos as $candidato) {
            $unidad = $this->porNombreOSimbolo($candidato) ?? $this->porSinonimo($candidato);

            if ($unidad) {
                return $unidad;
            }
        }

        foreach ($candidatos as $candidato) {
            if ($unidad = $this->porParecido($candidato)) {
                return $unidad;
            }
        }

        return null;
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
