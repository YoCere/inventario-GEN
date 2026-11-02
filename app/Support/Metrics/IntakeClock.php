<?php

namespace App\Support\Metrics;

use Illuminate\Support\Carbon;

/**
 * El reloj de un alta por el bot, guardado dentro del `data` de la conversación.
 *
 * Por qué ahí y no en una tabla nueva: la conversación ya es el lugar donde vive
 * todo lo que la persona fue contestando, ya tiene su propio vencimiento y se
 * borra sola al terminar. Una tabla aparte sería un segundo estado de lo mismo,
 * que habría que limpiar y que podría quedar desincronizado.
 *
 * Guarda tres cosas:
 *  - cuándo arrancó el alta de verdad (la primera pregunta, no el guardado),
 *  - por dónde entró (escribiendo o dictando), porque eso lo decide el arranque,
 *  - cuántos segundos se llevó cada paso, que es donde se ve dónde se traba la
 *    gente.
 *
 * Todos los métodos devuelven el arreglo nuevo y no tocan la base: el que llama
 * ya estaba guardando `data` de todos modos.
 */
final class IntakeClock
{
    /** Clave dentro de `data`. El guion bajo avisa que no es un dato del producto. */
    public const CLAVE = '_reloj';

    /**
     * Arranca el reloj si todavía no estaba andando.
     *
     * Idempotente a propósito: si la persona empieza dictando y después sigue
     * escribiendo, el alta sigue siendo la misma y el canal sigue siendo el del
     * arranque. Volver a arrancarlo borraría el tiempo ya medido.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function arrancar(array $data, string $canal, ?Carbon $ahora = null): array
    {
        if (isset($data[self::CLAVE]['inicio'])) {
            return $data;
        }

        $ahora ??= Carbon::now();

        $data[self::CLAVE] = [
            'inicio' => $ahora->toIso8601String(),
            'canal' => $canal,
            'paso_desde' => $ahora->toIso8601String(),
            'pasos' => [],
        ];

        return $data;
    }

    /**
     * Cierra el paso que se está dejando y suma su tiempo al acumulado.
     *
     * Se suma en vez de reemplazar porque un paso se puede visitar dos veces: la
     * persona contesta mal, el bot vuelve a preguntar, o lo corrige desde el
     * resumen. Lo que interesa es el tiempo total que ese paso le costó.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function cerrarPaso(array $data, ?string $paso, ?Carbon $ahora = null): array
    {
        if (! isset($data[self::CLAVE]['inicio'])) {
            return $data;
        }

        $ahora ??= Carbon::now();
        $reloj = $data[self::CLAVE];
        $paso = self::normalizar($paso);

        if ($paso !== null && $paso !== '' && array_key_exists($paso, TimedActivity::pasos())) {
            $desde = $reloj['paso_desde'] ?? $reloj['inicio'];
            $transcurrido = max(0, Carbon::parse($desde)->diffInSeconds($ahora, false));

            $pasos = is_array($reloj['pasos'] ?? null) ? $reloj['pasos'] : [];
            $pasos[$paso] = (int) ($pasos[$paso] ?? 0) + (int) $transcurrido;
            $reloj['pasos'] = $pasos;
        }

        $reloj['paso_desde'] = $ahora->toIso8601String();
        $data[self::CLAVE] = $reloj;

        return $data;
    }

    /**
     * Corregir un dato tiene un paso por campo ('nuevo:editar:precio_venta').
     * Se cuentan todos juntos como "corregir un dato": lo que interesa es cuánto
     * tiempo se va en arreglar lo que salió mal, no en arreglar qué.
     */
    private static function normalizar(?string $paso): ?string
    {
        if ($paso !== null && str_starts_with($paso, 'nuevo:editar:')) {
            return 'nuevo:editar';
        }

        return $paso;
    }

    /** @param array<string, mixed> $data */
    public static function inicio(array $data): ?string
    {
        $inicio = $data[self::CLAVE]['inicio'] ?? null;

        return is_string($inicio) ? $inicio : null;
    }

    /** @param array<string, mixed> $data */
    public static function canal(array $data): string
    {
        $canal = $data[self::CLAVE]['canal'] ?? null;

        return is_string($canal) && TimedActivity::existeCanal($canal)
            ? $canal
            : TimedActivity::CANAL_BOT;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, int>
     */
    public static function pasos(array $data): array
    {
        $pasos = $data[self::CLAVE]['pasos'] ?? [];

        return is_array($pasos) ? $pasos : [];
    }
}
