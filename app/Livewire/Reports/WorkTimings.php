<?php

namespace App\Livewire\Reports;

use App\Models\ActivityTiming;
use App\Support\BusinessTime;
use App\Support\Metrics\TimedActivity;
use Livewire\Component;

/**
 * Cuánto se tarda en cargar un producto y en cobrar una venta, por canal.
 *
 * La mediana va primero y en grande; el promedio, al lado y más chico. No es un
 * capricho de diseño: una sola carga de 14 minutos (alguien que atendió a un
 * cliente en medio del alta) empuja el promedio a un número que no le pasa a
 * nadie. "La mitad de las cargas tarda menos que esto" es lo que el dueño puede
 * prometerle a un cliente nuevo sin exagerar.
 *
 * Las mediciones descartadas (abandonos) no entran en ningún número, pero se
 * cuentan aparte: saber que hubo cinco altas abandonadas también es información.
 */
class WorkTimings extends Component
{
    /** Período del negocio: claves de App\Support\BusinessTime::periodRange(), o 'todo'. */
    public string $period = 'this_month';

    protected $queryString = ['period'];

    /** @return array<string, string> */
    public function periodosDisponibles(): array
    {
        return [
            'today' => 'Hoy',
            'this_week' => 'Esta semana',
            'this_month' => 'Este mes',
            'last_month' => 'Mes pasado',
            'todo' => 'Desde siempre',
        ];
    }

    public function render()
    {
        [$desde, $hasta] = $this->rangoDeDias();

        return view('livewire.reports.work-timings', [
            'resumen' => $this->resumen($desde, $hasta),
            'pasosDelBot' => $this->pasosDelBot($desde, $hasta),
            'periodos' => $this->periodosDisponibles(),
        ]);
    }

    /**
     * Días del negocio a mirar. Se filtra por business_date y no por created_at
     * porque con la zona horaria de Bolivia una venta de las 21:00 caería en el
     * día siguiente.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function rangoDeDias(): array
    {
        if ($this->period === 'todo') {
            return [null, null];
        }

        $rango = BusinessTime::periodRange($this->period);

        if ($rango === null) {
            // Período desconocido (alguien editó la URL): se muestra el mes.
            $rango = BusinessTime::periodRange('this_month');
        }

        return [$rango[0]->toDateString(), $rango[1]->toDateString()];
    }

    /**
     * Una fila por actividad, con una sub-fila por canal.
     *
     * @return array<int, array<string, mixed>>
     */
    private function resumen(?string $desde, ?string $hasta): array
    {
        $filas = [];

        foreach (TimedActivity::actividades() as $actividad => $definicion) {
            $canales = [];

            foreach (array_keys(TimedActivity::canales()) as $canal) {
                $canales[] = $this->numerosDe($actividad, $canal, $desde, $hasta);
            }

            // Un canal sin una sola medición no ocupa lugar en la pantalla.
            $canales = array_values(array_filter(
                $canales,
                fn (array $c) => $c['cantidad'] > 0 || $c['abandonadas'] > 0
            ));

            $filas[] = [
                'clave' => $actividad,
                'label' => $definicion['label'],
                'unidad' => $definicion['unidad'],
                'plural' => $definicion['plural'],
                'tope' => TimedActivity::enPalabras($definicion['tope_segundos']),
                'motivo' => $definicion['motivo'],
                'total' => $this->numerosDe($actividad, null, $desde, $hasta),
                'canales' => $canales,
            ];
        }

        return $filas;
    }

    /**
     * Cuántas, mediana y promedio. Trae solo la columna de segundos: la mediana
     * se calcula en PHP (MySQL no tiene mediana) y estas filas son pocas, una
     * por venta o por alta.
     *
     * @return array<string, mixed>
     */
    private function numerosDe(string $actividad, ?string $canal, ?string $desde, ?string $hasta): array
    {
        $base = ActivityTiming::query()
            ->deActividad($actividad)
            ->entreDias($desde, $hasta)
            ->when($canal !== null, fn ($q) => $q->delCanal($canal));

        $segundos = (clone $base)->queCuentan()->pluck('seconds');
        $abandonadas = (clone $base)->where('discarded', true)->count();

        return [
            'canal' => $canal,
            'canal_label' => $canal === null ? 'Todos los caminos' : TimedActivity::etiquetaCanal($canal),
            'cantidad' => $segundos->count(),
            'mediana' => ActivityTiming::mediana($segundos),
            'mediana_label' => TimedActivity::enPalabras(ActivityTiming::mediana($segundos)),
            'promedio' => $segundos->isEmpty() ? null : $segundos->avg(),
            'promedio_label' => TimedActivity::enPalabras($segundos->isEmpty() ? null : $segundos->avg()),
            'mas_rapida_label' => TimedActivity::enPalabras($segundos->isEmpty() ? null : $segundos->min()),
            'abandonadas' => $abandonadas,
        ];
    }

    /**
     * Dónde se traba la gente en el alta por el bot: mediana de segundos por
     * paso, en el orden en que el bot los pregunta.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pasosDelBot(?string $desde, ?string $hasta): array
    {
        $mediciones = ActivityTiming::query()
            ->deActividad(TimedActivity::ALTA_PRODUCTO)
            ->queCuentan()
            ->entreDias($desde, $hasta)
            ->whereIn('channel', [TimedActivity::CANAL_BOT, TimedActivity::CANAL_BOT_VOZ])
            ->whereNotNull('steps')
            ->pluck('steps');

        // Acumula por paso respetando el orden del catálogo, que es el orden en
        // que la persona los vive.
        $porPaso = [];
        foreach ($mediciones as $pasos) {
            foreach ((array) $pasos as $paso => $segundos) {
                $porPaso[$paso][] = (int) $segundos;
            }
        }

        $filas = [];
        foreach (TimedActivity::pasos() as $paso => $label) {
            if (empty($porPaso[$paso])) {
                continue;
            }

            $valores = $porPaso[$paso];
            $mediana = ActivityTiming::mediana($valores);

            $filas[] = [
                'paso' => $paso,
                'label' => $label,
                'veces' => count($valores),
                'mediana' => $mediana,
                'mediana_label' => TimedActivity::enPalabras($mediana),
                'mas_lenta_label' => TimedActivity::enPalabras(max($valores)),
            ];
        }

        // La barra de la pantalla se dibuja contra el paso más lento, así cada
        // fila se lee sola sin tener que comparar números a ojo.
        $peor = max(array_merge([1], array_map(fn ($f) => (int) $f['mediana'], $filas)));
        foreach ($filas as $i => $fila) {
            $filas[$i]['porcentaje'] = (int) round(($fila['mediana'] / $peor) * 100);
        }

        return $filas;
    }
}
