{{--
    Pantalla de tiempos. Dos decisiones de lectura:

    1. La mediana va grande y el promedio chico al lado. Una sola carga de 14
       minutos levanta el promedio y deja un número que no le pasa a nadie.
    2. Los abandonos se muestran aparte en vez de esconderse: que hubo cinco
       altas que alguien dejó por la mitad también es información.
--}}
<div class="space-y-6">

    {{-- Período --}}
    <div class="flex flex-wrap items-center gap-2">
        <span class="text-sm text-muted-foreground">Período:</span>
        @foreach ($periodos as $clave => $label)
            <button type="button" wire:click="$set('period', '{{ $clave }}')"
                class="rounded-md border px-3 py-1.5 text-sm transition-colors
                    {{ $period === $clave
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'border-border bg-card text-foreground hover:bg-muted' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    @foreach ($resumen as $bloque)
        <div class="rounded-lg border border-border bg-card overflow-hidden">
            <div class="border-b border-border px-4 py-3">
                <h3 class="font-semibold text-foreground">{{ $bloque['label'] }}</h3>
                <p class="text-sm text-muted-foreground">
                    @if ($bloque['total']['cantidad'] > 0)
                        La mitad de {{ $bloque['plural'] }} se hace en menos de
                        <span class="font-semibold text-foreground">{{ $bloque['total']['mediana_label'] }}</span>.
                        La más rápida tardó {{ $bloque['total']['mas_rapida_label'] }}.
                    @else
                        Todavía no hay ninguna medición en este período.
                    @endif
                </p>
            </div>

            @if ($bloque['canales'] === [])
                <p class="px-4 py-6 text-sm text-muted-foreground">
                    Cuando se carguen productos o se cobren ventas en este período, los tiempos aparecen acá.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-muted text-xs uppercase text-muted-foreground">
                            <tr>
                                <th class="px-4 py-3 text-left">Por dónde se hizo</th>
                                <th class="px-4 py-3 text-right">Cuántas</th>
                                <th class="px-4 py-3 text-right">La mitad tarda menos de</th>
                                <th class="px-4 py-3 text-right">Promedio</th>
                                <th class="px-4 py-3 text-right">La más rápida</th>
                                <th class="px-4 py-3 text-right">Sin terminar a tiempo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($bloque['canales'] as $canal)
                                <tr>
                                    <td class="px-4 py-3 font-medium text-foreground">{{ $canal['canal_label'] }}</td>
                                    <td class="px-4 py-3 text-right text-foreground">{{ number_format($canal['cantidad']) }}</td>
                                    <td class="px-4 py-3 text-right text-lg font-bold text-primary">{{ $canal['mediana_label'] }}</td>
                                    <td class="px-4 py-3 text-right text-muted-foreground">{{ $canal['promedio_label'] }}</td>
                                    <td class="px-4 py-3 text-right text-muted-foreground">{{ $canal['mas_rapida_label'] }}</td>
                                    <td class="px-4 py-3 text-right text-muted-foreground">
                                        {{ $canal['abandonadas'] > 0 ? number_format($canal['abandonadas']) : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        @if (count($bloque['canales']) > 1)
                            <tfoot class="bg-muted/50">
                                <tr>
                                    <td class="px-4 py-3 font-semibold text-foreground">{{ $bloque['total']['canal_label'] }}</td>
                                    <td class="px-4 py-3 text-right font-semibold text-foreground">{{ number_format($bloque['total']['cantidad']) }}</td>
                                    <td class="px-4 py-3 text-right text-lg font-bold text-primary">{{ $bloque['total']['mediana_label'] }}</td>
                                    <td class="px-4 py-3 text-right text-muted-foreground">{{ $bloque['total']['promedio_label'] }}</td>
                                    <td class="px-4 py-3 text-right text-muted-foreground">{{ $bloque['total']['mas_rapida_label'] }}</td>
                                    <td class="px-4 py-3 text-right text-muted-foreground">
                                        {{ $bloque['total']['abandonadas'] > 0 ? number_format($bloque['total']['abandonadas']) : '—' }}
                                    </td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            @endif

            <p class="border-t border-border px-4 py-3 text-xs text-muted-foreground">
                No se cuentan las que pasaron de {{ $bloque['tope'] }}: {{ $bloque['motivo'] }}
            </p>
        </div>
    @endforeach

    {{-- Dónde se traba la gente cuando carga por el bot --}}
    <div class="rounded-lg border border-border bg-card overflow-hidden">
        <div class="border-b border-border px-4 py-3">
            <h3 class="font-semibold text-foreground">Dónde se tarda más al cargar por el bot</h3>
            <p class="text-sm text-muted-foreground">
                Tiempo que lleva contestar cada pregunta del bot. El dato que más se tarda es el que conviene
                simplificar o dejar de pedir en Ajustes.
            </p>
        </div>

        @if ($pasosDelBot === [])
            <p class="px-4 py-6 text-sm text-muted-foreground">
                Todavía no hay altas hechas por el bot en este período.
            </p>
        @else
            <div class="divide-y divide-border">
                @foreach ($pasosDelBot as $paso)
                    <div class="px-4 py-3">
                        <div class="flex items-baseline justify-between gap-4">
                            <span class="font-medium text-foreground">{{ $paso['label'] }}</span>
                            <span class="text-sm text-muted-foreground">
                                <span class="font-semibold text-foreground">{{ $paso['mediana_label'] }}</span>
                                · {{ $paso['veces'] }} {{ $paso['veces'] === 1 ? 'vez' : 'veces' }}
                                · la peor {{ $paso['mas_lenta_label'] }}
                            </span>
                        </div>
                        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-muted">
                            <div class="h-2 rounded-full bg-primary" style="width: {{ max(2, $paso['porcentaje']) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
