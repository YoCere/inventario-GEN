<?php

namespace App\Support\Metrics;

/**
 * Qué se mide, por dónde entró, cómo se llama en pantalla y a partir de cuándo
 * una medición ya no representa nada.
 *
 * Fuente única (mismo patrón que App\Settings\ProductIntakeFields): la pantalla
 * de métricas, el que escribe la medición y los tests leen de acá. Sin esto,
 * cada lado tendría su propia lista de canales y terminarían contando distinto
 * lo mismo.
 *
 * Las claves que se guardan en la base son internas; lo que la persona lee sale
 * siempre de las etiquetas de este catálogo.
 */
final class TimedActivity
{
    /** Dar de alta un producto en el catálogo. */
    public const ALTA_PRODUCTO = 'alta_producto';

    /** Cobrar una venta. */
    public const VENTA = 'venta';

    /** Desde el sistema, en el navegador. */
    public const CANAL_WEB = 'web';

    /** Por chat con el bot, escribiendo. */
    public const CANAL_BOT = 'bot';

    /** Por chat con el bot, dictando un audio. */
    public const CANAL_BOT_VOZ = 'bot_voz';

    /**
     * Las dos cosas que se miden.
     *
     * `tope_segundos` es el límite a partir del cual la medición se guarda pero
     * no cuenta para los promedios. El motivo de cada número está en 'motivo' y
     * se muestra en pantalla: nadie tiene que leer este archivo para entender
     * por qué una carga quedó afuera.
     *
     * @return array<string, array{label: string, unidad: string, plural: string, tope_segundos: int, motivo: string}>
     */
    public static function actividades(): array
    {
        return [
            self::ALTA_PRODUCTO => [
                'label' => 'Carga de productos',
                'unidad' => 'carga',
                'plural' => 'cargas',
                // 15 minutos. El bot da por vencida una conversación de alta a
                // los 30 minutos sin respuesta (expires_at), así que la mitad de
                // esa ventana es el límite natural: un alta de siete preguntas
                // que pasa de 15 minutos es alguien que dejó el teléfono o el
                // formulario abierto, no una carga lenta.
                'tope_segundos' => 15 * 60,
                'motivo' => 'Una carga que pasa de 15 minutos es alguien que dejó el formulario abierto y volvió después, no una carga lenta.',
            ],
            self::VENTA => [
                'label' => 'Ventas cobradas',
                'unidad' => 'venta',
                'plural' => 'ventas',
                // 10 minutos. En el mostrador el reloj arranca cuando la
                // pantalla queda libre para el próximo cliente; si pasan más de
                // 10 minutos lo que se midió es el mostrador vacío, no el cobro.
                'tope_segundos' => 10 * 60,
                'motivo' => 'Una venta que pasa de 10 minutos es mostrador vacío esperando al próximo cliente, no un cobro lento.',
            ],
        ];
    }

    /**
     * Por dónde se hizo el trabajo.
     *
     * @return array<string, string>
     */
    public static function canales(): array
    {
        return [
            self::CANAL_WEB => 'En el sistema',
            self::CANAL_BOT => 'Por chat con el bot',
            self::CANAL_BOT_VOZ => 'Dictado por voz al bot',
        ];
    }

    /**
     * Cómo se llama cada paso del alta por el bot, en el orden en que se piden.
     *
     * Las claves son los pasos reales de la conversación (App\Services\Telegram
     * \PasosDelAlta). Están acá y no allá porque lo que necesita la pantalla de
     * métricas es el nombre que lee la persona.
     *
     * @return array<string, string>
     */
    public static function pasos(): array
    {
        return [
            'nuevo:nombre' => 'Nombre',
            'nuevo:categoria' => 'Categoría',
            'nuevo:unidad' => 'Cómo se vende',
            'nuevo:precio_compra' => 'Precio de compra',
            'nuevo:precio_venta' => 'Precio de venta',
            'nuevo:cantidad' => 'Cantidad inicial',
            'nuevo:stock_minimo' => 'Avisar cuando queden pocos',
            'nuevo:foto' => 'Fotos',
            'nuevo:revisar' => 'Revisar antes de guardar',
            'nuevo:editar' => 'Corregir un dato',
        ];
    }

    public static function existeActividad(string $actividad): bool
    {
        return array_key_exists($actividad, self::actividades());
    }

    public static function existeCanal(string $canal): bool
    {
        return array_key_exists($canal, self::canales());
    }

    /** Tope de la actividad; el más chico de los dos si la actividad no se reconoce. */
    public static function topeSegundos(string $actividad): int
    {
        $actividades = self::actividades();

        return $actividades[$actividad]['tope_segundos']
            ?? min(array_column($actividades, 'tope_segundos'));
    }

    public static function etiquetaActividad(string $actividad): string
    {
        return self::actividades()[$actividad]['label'] ?? $actividad;
    }

    public static function etiquetaCanal(string $canal): string
    {
        return self::canales()[$canal] ?? $canal;
    }

    public static function etiquetaPaso(string $paso): string
    {
        return self::pasos()[$paso] ?? $paso;
    }

    /**
     * Un tiempo en segundos dicho como lo diría una persona: "1 min 20 s".
     *
     * Vive acá para que la pantalla, un futuro informe impreso y cualquier
     * mensaje del bot digan el tiempo igual.
     */
    public static function enPalabras(?float $segundos): string
    {
        if ($segundos === null) {
            return 'sin datos';
        }

        $segundos = (int) round($segundos);

        if ($segundos < 60) {
            return $segundos . ' s';
        }

        $minutos = intdiv($segundos, 60);
        $resto = $segundos % 60;

        return $resto === 0
            ? $minutos . ' min'
            : $minutos . ' min ' . $resto . ' s';
    }
}
