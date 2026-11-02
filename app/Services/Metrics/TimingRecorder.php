<?php

namespace App\Services\Metrics;

use App\Models\ActivityTiming;
use App\Support\BusinessTime;
use App\Support\Metrics\TimedActivity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Anota cuánto tardó un alta o una venta. Lo hace o no lo hace, pero NUNCA
 * rompe nada.
 *
 * Regla que no se negocia: es plata y mercadería real. Medir es un dato lindo
 * para mostrarle a un cliente nuevo; la venta y el alta son el negocio. Por eso:
 *
 *  1. Esta clase se llama SIEMPRE después de que la venta o el producto ya están
 *     guardados y la transacción cerró. Si falla, lo que importa ya existe.
 *  2. registrar() atrapa Throwable: tabla que no existe, tabla bloqueada, dato
 *     imposible, cualquier cosa. Queda un aviso en el log y se sigue.
 *
 * Hay un test que lo demuestra: borra la tabla de mediciones y comprueba que la
 * venta se cobra y el producto se crea igual.
 */
class TimingRecorder
{
    /**
     * @param  string  $actividad  una de App\Support\Metrics\TimedActivity::actividades()
     * @param  string  $canal  uno de TimedActivity::canales()
     * @param  mixed  $inicio  cuándo empezó de verdad la persona (ISO, timestamp o Carbon); null = no hay nada que medir
     * @param  array<string, int>  $pasos  segundos por paso, solo para el alta por el bot
     * @return ActivityTiming|null la medición, o null si no se pudo o no correspondía
     */
    public function registrar(
        string $actividad,
        string $canal,
        ?int $userId,
        mixed $inicio,
        array $pasos = [],
        mixed $fin = null,
    ): ?ActivityTiming {
        try {
            return $this->guardar($actividad, $canal, $userId, $inicio, $pasos, $fin);
        } catch (\Throwable $e) {
            // A propósito warning y no error: no hay nada que reparar en el
            // negocio, solo se perdió un dato de la estadística.
            Log::warning('No se pudo anotar el tiempo de ' . $actividad, [
                'canal' => $canal,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, int>  $pasos
     */
    private function guardar(
        string $actividad,
        string $canal,
        ?int $userId,
        mixed $inicio,
        array $pasos,
        mixed $fin,
    ): ?ActivityTiming {
        // Sin reloj no hay medición. Pasa con las altas y las ventas que venían
        // en curso cuando se instaló esto: medio dato es peor que ninguno.
        $desde = $this->instante($inicio);
        if ($desde === null) {
            return null;
        }

        if (! TimedActivity::existeActividad($actividad) || ! TimedActivity::existeCanal($canal)) {
            Log::warning('Medición de tiempo con actividad o canal desconocido', [
                'actividad' => $actividad,
                'canal' => $canal,
            ]);

            return null;
        }

        $hasta = $this->instante($fin) ?? Carbon::now();
        $segundos = $desde->diffInSeconds($hasta, false);

        // Un reloj hacia atrás (hora del equipo cambiada, valor manipulado desde
        // el navegador) se guarda en cero y marcado: no se descarta en silencio.
        $imposible = $segundos < 0;
        $segundos = max(0, (int) $segundos);

        $pasada = $segundos > TimedActivity::topeSegundos($actividad);

        return ActivityTiming::create([
            'activity' => $actividad,
            'channel' => $canal,
            'user_id' => $userId,
            'seconds' => $segundos,
            'steps' => $this->pasosLimpios($pasos),
            'started_at' => $desde->utc(),
            'finished_at' => $hasta->utc(),
            // El día del negocio, no el día UTC: con la zona de Bolivia una venta
            // de las 21:00 se contaría en el día siguiente.
            'business_date' => BusinessTime::now()->toDateString(),
            'discarded' => $imposible || $pasada,
        ]);
    }

    /** Acepta Carbon, ISO 8601, 'Y-m-d H:i:s' o un timestamp. */
    private function instante(mixed $valor): ?Carbon
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if ($valor instanceof \DateTimeInterface) {
            return Carbon::instance($valor);
        }

        if (is_int($valor) || (is_string($valor) && ctype_digit($valor))) {
            return Carbon::createFromTimestamp((int) $valor);
        }

        try {
            return Carbon::parse((string) $valor);
        } catch (\Throwable) {
            // Un reloj con un valor ilegible no es motivo para perder la fila:
            // simplemente no hay medición.
            return null;
        }
    }

    /**
     * Deja solo pasos conocidos con segundos sanos. Un paso inventado o un
     * número negativo se cae acá y no llega a la pantalla.
     *
     * @param  array<string, mixed>  $pasos
     * @return array<string, int>|null
     */
    private function pasosLimpios(array $pasos): ?array
    {
        $limpios = [];

        foreach ($pasos as $paso => $segundos) {
            if (! is_string($paso) || ! array_key_exists($paso, TimedActivity::pasos())) {
                continue;
            }
            if (! is_numeric($segundos) || $segundos < 0) {
                continue;
            }
            $limpios[$paso] = (int) $segundos;
        }

        return $limpios === [] ? null : $limpios;
    }
}
