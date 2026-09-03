<div class="py-4">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-card border border-border rounded-lg shadow-sm">
            <div class="p-6 border-b border-border">
                <h3 class="text-lg font-semibold text-foreground">Cerrar gestión</h3>
                <p class="text-sm text-muted-foreground mt-1">
                    Provisiona el IUE (25%) y la reserva legal de la gestión. Revisá el detalle y confirmá.
                    No hace el cierre de resultados; los reportes ya calculan el resultado por rango.
                </p>
            </div>

            <div class="p-6 space-y-6">
                @php $p = $this->preview; @endphp

                {{-- Gestión --}}
                <div class="space-y-2 max-w-xs">
                    <x-input-label for="year" value="Gestión (año)" />
                    <input id="year" type="number" wire:model.live="year"
                        class="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring" />
                    <x-input-error :messages="$errors->get('year')" />
                </div>

                @if($p['ya_cerrada'])
                    <div class="rounded-md border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-700 dark:text-amber-400">
                        Esta gestión ya está cerrada.
                    </div>
                @endif

                {{-- Preview --}}
                <div class="rounded-md border border-border divide-y divide-border text-sm">
                    <div class="flex justify-between px-4 py-2">
                        <span class="text-muted-foreground">Utilidad antes de impuestos</span>
                        <span class="font-medium text-foreground">{{ number_format($p['utilidad_antes_impuestos'] / 100, 2) }}</span>
                    </div>
                    <div class="flex justify-between px-4 py-2">
                        <span class="text-muted-foreground">IUE 25% (provisión)</span>
                        <span class="font-medium text-foreground">{{ number_format($p['iue'] / 100, 2) }}</span>
                    </div>
                    <div class="flex justify-between px-4 py-2">
                        <span class="text-muted-foreground">Reserva legal 5%</span>
                        <span class="font-medium text-foreground">{{ number_format($p['reserva_legal'] / 100, 2) }}</span>
                    </div>
                </div>

                @if(empty($p['lines']))
                    <p class="text-sm text-muted-foreground">No hay IUE ni reserva que provisionar para esta gestión (utilidad ≤ 0 o tipo societario sin reserva).</p>
                @endif

                @if(session('ok'))
                    <div class="rounded-md border border-green-500/40 bg-green-500/10 px-4 py-3 text-sm text-green-700 dark:text-green-400">
                        {{ session('ok') }}
                    </div>
                @endif

                <div>
                    <button type="button" wire:click="cerrar" @disabled($p['ya_cerrada'])
                        class="inline-flex items-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow-sm hover:bg-primary/90 disabled:opacity-50 disabled:cursor-not-allowed">
                        Cerrar gestión
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
