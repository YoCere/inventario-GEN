<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Zona horaria del negocio y "ahora" en esa zona.
 *
 * La app corre en UTC (config app.timezone). El negocio opera en su propia zona
 * (Setting 'business_timezone'). Centralizar aquí evita que cada consumidor
 * (agente IA, recordatorios) lea el setting por su cuenta y se desincronice.
 */
class BusinessTime
{
    /** Zona horaria del negocio; cae a la de la app si no está configurada o es inválida. */
    public static function timezone(): string
    {
        $tz = trim((string) Setting::get('business_timezone', ''));
        if ($tz === '') {
            return config('app.timezone');
        }

        // Una tz inválida en el setting reventaría Carbon::now($tz) y tumbaría al
        // agente. Validar y caer a la zona de la app si no es un identificador real.
        try {
            new \DateTimeZone($tz);
            return $tz;
        } catch (\Throwable) {
            return config('app.timezone');
        }
    }

    /** "Ahora" en la zona del negocio. */
    public static function now(): Carbon
    {
        return Carbon::now(static::timezone());
    }

    /**
     * Línea de contexto para el system prompt del agente IA. Le da al modelo la
     * fecha/hora actual real para que resuelva relativos ("hoy", "mañana") en vez
     * de adivinar con su fecha de entrenamiento.
     */
    public static function promptContext(): string
    {
        $now = static::now()->locale('es');
        $tz = static::timezone();

        return 'Fecha y hora actual: ' . $now->isoFormat('dddd D [de] MMMM [de] YYYY, HH:mm')
            . " (zona horaria {$tz}). "
            . "Resuelve fechas relativas como 'hoy', 'mañana', 'esta tarde' o 'la próxima semana' "
            . 'respecto a este momento. Al llamar a create_reminder, entrega remind_at en formato '
            . 'ISO 8601 con la hora local del negocio y SIN zona horaria (ej. 2026-06-20T16:00:00).';
    }
}
