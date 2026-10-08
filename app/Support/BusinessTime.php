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
 *
 * SEMÁNTICA DE LAS COLUMNAS DE FECHA DEL NEGOCIO
 * ----------------------------------------------
 * Las columnas de fecha contable/comercial (sales.sale_date, purchases.purchase_date,
 * finance_transactions.transaction_date, journal_entries.entry_date, los rangos de
 * accounting_periods, etc.) guardan la fecha/hora LOCAL del negocio sin zona
 * ("naive local"), NO un instante UTC.
 *
 * Se eligió así, y no "instante UTC + conversión al consultar", porque:
 *  - La mayoría de esas columnas son DATE (sin hora): un DATE no tiene instante que
 *    convertir, así que la variante UTC sería imposible de aplicar de forma uniforme.
 *  - Todo el código de presentación ya formatea esas fechas sin convertir zona
 *    (SalesTable, sales/show, sales/print, libros contables), o sea que ya asumía local.
 *  - Para el contador y el dueño, el "día" de una venta es el día local; que una venta
 *    de las 21:00 aparezca en el día siguiente es el bug que se está corrigiendo.
 *
 * En cambio los timestamps técnicos (created_at, updated_at, reminders.remind_at,
 * jobs, logs, backups) siguen siendo instantes UTC reales y se convierten solo al
 * mostrarlos. Para filtrar por día local sobre esas columnas usar dayRangeUtc().
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
     * Medianoche de hoy en la zona del negocio.
     *
     * Es el reemplazo de now()->startOfDay() en todo lo que signifique "el día del
     * negocio": con app.timezone=UTC, now() ya cambió de día a las 20:00 locales.
     */
    public static function today(): Carbon
    {
        return static::now()->startOfDay();
    }

    /** Hoy como 'Y-m-d' local: valor por defecto de los date pickers y de las fechas contables. */
    public static function todayString(): string
    {
        return static::now()->toDateString();
    }

    /**
     * Interpreta un valor en la zona del negocio.
     *
     * Un string sin zona ("2026-07-08", "2026-07-08 21:30:00") viene de un input del
     * usuario o de una columna naive local: Carbon::parse lo leería como UTC y lo
     * correría 4 horas. Los valores que YA traen zona (Carbon, ISO con offset) se
     * respetan y solo se trasladan a la zona del negocio.
     */
    public static function parse(mixed $value = null): Carbon
    {
        if ($value === null || $value === '') {
            return static::now();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->setTimezone(static::timezone());
        }

        // Un string con offset explícito ignora el 2º argumento de parse(), así que el
        // setTimezone posterior es el que lo normaliza a la zona del negocio.
        return Carbon::parse($value, static::timezone())->setTimezone(static::timezone());
    }

    /** Inicio del día local del valor dado (hoy si se omite). */
    public static function startOfDay(mixed $value = null): Carbon
    {
        return static::parse($value)->startOfDay();
    }

    /** Fin del día local del valor dado (hoy si se omite). */
    public static function endOfDay(mixed $value = null): Carbon
    {
        return static::parse($value)->endOfDay();
    }

    /**
     * Rango [inicio, fin] de un día local, para comparar contra columnas naive local.
     *
     * Las Carbon con zona se enlazan a la query con su hora de pared (Laravel las
     * formatea con format('Y-m-d H:i:s'), sin convertir), que es justo lo que guardan
     * esas columnas.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function dayRange(mixed $value = null): array
    {
        $day = static::parse($value);

        return [$day->copy()->startOfDay(), $day->copy()->endOfDay()];
    }

    /**
     * El mismo día local pero expresado en UTC, para columnas que SÍ guardan instantes
     * (created_at, updated_at). Un whereDate('created_at', hoy) compararía el día UTC.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function dayRangeUtc(mixed $value = null): array
    {
        [$start, $end] = static::dayRange($value);

        return [$start->utc(), $end->utc()];
    }

    /**
     * Medianoche local de una fecha que YA está expresada en hora local del negocio.
     *
     * Es el caso de las columnas DATE leídas de la base: Eloquent las devuelve como
     * Carbon en la zona de la app (UTC) con la fecha local adentro, así que un
     * setTimezone() las correría un día. Aquí se usa solo la parte de fecha.
     */
    public static function localDate(mixed $value): Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }

        return static::startOfDay(substr((string) $value, 0, 10));
    }

    /**
     * Hoy (fecha local del negocio) con la MISMA representación que Eloquent devuelve
     * para una columna DATE: naive, en la zona de la app.
     *
     * Es lo que hay que usar para compararlo contra $model->end_date y similares; con
     * una Carbon en zona del negocio el desfase de 4 h inventa un día de diferencia.
     */
    public static function todayAsDate(): Carbon
    {
        return Carbon::parse(static::todayString());
    }

    /** Días que faltan hasta una fecha local del negocio; negativo si ya pasó. */
    public static function daysUntil(mixed $date): int
    {
        return (int) static::today()->diffInDays(static::localDate($date), false);
    }

    /**
     * Rango [inicio, fin] de un período con nombre, en hora local del negocio.
     *
     * Único lugar donde se traduce "hoy"/"esta semana"/"este mes" a fechas, para que
     * el dashboard y los filtros de las tablas no se desincronicen entre sí.
     *
     * @return array{0: Carbon, 1: Carbon}|null null si el período no se reconoce
     */
    public static function periodRange(string $period): ?array
    {
        $now = static::now();

        return match ($period) {
            'today'      => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday'  => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'this_week'  => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'last_week'  => [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            // subMonthNoOverflow: desde el 31 de marzo, subMonth() cae en el 3 de marzo
            // y "mes pasado" devolvería marzo otra vez.
            'last_month' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            default      => null,
        };
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
