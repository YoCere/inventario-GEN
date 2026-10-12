<?php

namespace App\Settings;

use App\Models\Setting;

/**
 * Qué datos se piden al dar de alta un producto.
 *
 * Fuente única: la pantalla de Ajustes, el bot de Telegram y el formulario web
 * leen de acá. Sin esto cada lado tendría su propia lista y terminarían
 * diciendo cosas distintas sobre el mismo producto.
 *
 * Dos reglas que no se negocian:
 *
 *  1. **Hay datos que no se pueden apagar.** Sin nombre ni precios, un producto
 *     no sirve para vender ni para saber cuánto se gana. `OBLIGATORIOS` manda, y
 *     manda del lado del servidor: no alcanza con deshabilitar la casilla en
 *     pantalla, porque eso se saltea desde el navegador en diez segundos.
 *
 *  2. **Lo que se apaga tiene un valor por defecto visible.** El sistema no
 *     vuelve a adivinar a escondidas, que es justo lo que la dueña pidió evitar
 *     ("que pida stock mínimo, para que no adivine el sistema"). Si no se
 *     pregunta, se usa un valor que ella puede ver y cambiar en Ajustes.
 */
final class ProductIntakeFields
{
    /** Prefijo de las claves en la tabla settings: 'intake_categoria'. */
    private const PREFIJO = 'intake_';

    /** Prefijo de los valores por defecto: 'intake_default_stock_minimo'. */
    private const PREFIJO_DEFECTO = 'intake_default_';

    /**
     * Datos imprescindibles. No se pueden apagar desde ningún lado.
     *
     * @var array<int, string>
     */
    public const OBLIGATORIOS = ['nombre', 'precio_compra', 'precio_venta'];

    /**
     * Todo lo que se puede pedir, en el orden en que se pregunta.
     *
     * - label        cómo se llama en pantalla (lenguaje de negocio)
     * - help         por qué conviene pedirlo
     * - defecto      valor que se usa cuando está apagado
     * - defecto_key  ajuste donde la dueña puede cambiar ese valor (si aplica)
     *
     * @return array<string, array{label: string, help: string, defecto: string|int|bool|null, defecto_key: string|null}>
     */
    public static function catalogo(): array
    {
        return [
            'nombre' => [
                'label' => 'Nombre del producto',
                'help' => 'Sin nombre no se puede buscar ni vender. No se puede desactivar.',
                'defecto' => null,
                'defecto_key' => null,
            ],
            'categoria' => [
                'label' => 'Categoría',
                'help' => 'Sirve para ordenar el catálogo y los reportes.',
                'defecto' => 'General',
                'defecto_key' => self::PREFIJO_DEFECTO . 'categoria',
            ],
            'unidad' => [
                'label' => 'Cómo se vende (unidad, caja, par…)',
                'help' => 'Define qué significa "1" en una venta.',
                'defecto' => 'Unidad',
                'defecto_key' => self::PREFIJO_DEFECTO . 'unidad',
            ],
            'precio_compra' => [
                'label' => 'Precio de compra',
                'help' => 'Sin esto no se puede saber cuánto se gana. No se puede desactivar.',
                'defecto' => null,
                'defecto_key' => null,
            ],
            'precio_venta' => [
                'label' => 'Precio de venta',
                'help' => 'Es lo que se le cobra al cliente. No se puede desactivar.',
                'defecto' => null,
                'defecto_key' => null,
            ],
            'cantidad' => [
                'label' => 'Cantidad inicial',
                'help' => 'Cuántos hay ahora. Si no se pregunta, entra en cero.',
                'defecto' => '0',
                'defecto_key' => self::PREFIJO_DEFECTO . 'cantidad',
            ],
            'stock_minimo' => [
                'label' => 'Avisar cuando queden pocos',
                'help' => 'Con cuántas unidades querés que te avise para reponer.',
                'defecto' => '1',
                'defecto_key' => self::PREFIJO_DEFECTO . 'stock_minimo',
            ],
            'foto' => [
                'label' => 'Foto',
                'help' => 'Se ve en la tienda en línea y ayuda a reconocer el producto.',
                'defecto' => null,
                'defecto_key' => null,
            ],
            'publicar' => [
                'label' => 'Mostrar en la tienda en línea',
                'help' => 'Si está apagado, los productos nuevos salen igual a la tienda.',
                'defecto' => '1',
                'defecto_key' => self::PREFIJO_DEFECTO . 'publicar',
            ],
        ];
    }

    /** Los que se pueden apagar, en orden. @return array<int, string> */
    public static function configurables(): array
    {
        return array_values(array_diff(array_keys(self::catalogo()), self::OBLIGATORIOS));
    }

    public static function esObligatorio(string $campo): bool
    {
        return in_array($campo, self::OBLIGATORIOS, true);
    }

    public static function existe(string $campo): bool
    {
        return array_key_exists($campo, self::catalogo());
    }

    /** Clave en la tabla settings para prender o apagar un dato. */
    public static function clave(string $campo): string
    {
        return self::PREFIJO . $campo;
    }

    /**
     * ¿Se pregunta este dato?
     *
     * Los obligatorios devuelven true siempre, sin mirar la base: aunque alguien
     * escriba un '0' en settings a mano, el nombre y los precios se siguen
     * pidiendo. Es la defensa del lado del servidor de la regla 1.
     */
    public static function pide(string $campo): bool
    {
        if (! self::existe($campo)) {
            return false;
        }

        if (self::esObligatorio($campo)) {
            return true;
        }

        return Setting::get(self::clave($campo), '1') === '1';
    }

    /** @return array<int, string> los datos que hoy se preguntan, en orden */
    public static function activos(): array
    {
        return array_values(array_filter(array_keys(self::catalogo()), fn (string $campo) => self::pide($campo)));
    }

    /**
     * Qué se usa cuando el dato está apagado. Nunca se adivina en silencio: esto
     * sale de un ajuste que la dueña ve, o del valor de fábrica del catálogo.
     */
    public static function porDefecto(string $campo): string|int|bool|null
    {
        $catalogo = self::catalogo();

        if (! isset($catalogo[$campo])) {
            return null;
        }

        $definicion = $catalogo[$campo];

        if ($definicion['defecto_key'] === null) {
            return $definicion['defecto'];
        }

        $guardado = Setting::get($definicion['defecto_key'], null);

        return $guardado !== null && $guardado !== '' ? $guardado : $definicion['defecto'];
    }
}
