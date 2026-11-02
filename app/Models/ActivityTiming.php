<?php

namespace App\Models;

use App\Support\Metrics\TimedActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Una medición: cuánto tardó alguien en cargar un producto o en cobrar una venta.
 *
 * Nunca se escribe desde el flujo de negocio directamente: lo hace
 * App\Services\Metrics\TimingRecorder, que se come cualquier error para que una
 * medición fallida no se lleve puesta una venta.
 */
class ActivityTiming extends Model
{
    protected $fillable = [
        'activity', 'channel', 'user_id', 'seconds', 'steps',
        'started_at', 'finished_at', 'business_date', 'discarded',
    ];

    protected $casts = [
        'steps' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'business_date' => 'date',
        'discarded' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Las que cuentan para los promedios: sin los abandonos ni los datos imposibles. */
    public function scopeQueCuentan(Builder $query): Builder
    {
        return $query->where('discarded', false);
    }

    public function scopeDeActividad(Builder $query, string $actividad): Builder
    {
        return $query->where('activity', $actividad);
    }

    public function scopeDelCanal(Builder $query, string $canal): Builder
    {
        return $query->where('channel', $canal);
    }

    /** Rango de días del negocio (columna business_date, no created_at). */
    public function scopeEntreDias(Builder $query, ?string $desde, ?string $hasta): Builder
    {
        return $query
            ->when($desde, fn (Builder $q) => $q->where('business_date', '>=', $desde))
            ->when($hasta, fn (Builder $q) => $q->where('business_date', '<=', $hasta));
    }

    /**
     * La mediana de una lista de segundos.
     *
     * Importa más que el promedio: una sola carga de 14 minutos (alguien que
     * atendió a un cliente en medio del alta) empuja el promedio a un número que
     * no le pasa a nadie. La mediana dice "la mitad de las cargas tarda menos
     * que esto", que es lo que el dueño quiere poder prometer.
     *
     * Se calcula en PHP y no con una función de la base porque MySQL no tiene
     * mediana y el volumen de estas filas es chico (una por venta o alta).
     *
     * @param  iterable<int|float>  $valores
     */
    public static function mediana(iterable $valores): ?float
    {
        $ordenados = Collection::make($valores)
            ->map(fn ($v) => (float) $v)
            ->sort()
            ->values();

        $total = $ordenados->count();

        if ($total === 0) {
            return null;
        }

        $medio = intdiv($total, 2);

        // Cantidad par: el promedio de los dos del medio. Con cantidad impar es
        // el del medio, sin promediar nada.
        return $total % 2 === 1
            ? $ordenados[$medio]
            : ($ordenados[$medio - 1] + $ordenados[$medio]) / 2;
    }

    /** El tiempo de esta medición dicho como lo diría una persona. */
    public function enPalabras(): string
    {
        return TimedActivity::enPalabras($this->seconds);
    }
}
