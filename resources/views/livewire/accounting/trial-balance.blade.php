<div>
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">

        {{-- Controles: Período + Ajustado --}}
        <div class="bg-card border border-border rounded-lg p-4 flex flex-wrap items-center gap-4">
            <div class="space-y-1">
                <label class="text-xs font-semibold text-muted-foreground uppercase tracking-wide">Período</label>
                <select wire:model.live="periodId"
                    class="block rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring">
                    <option value="">— Seleccionar —</option>
                    @foreach($periods as $p)
                        <option value="{{ $p->id }}">{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-center gap-2 pt-5">
                <input type="checkbox" id="adjusted" wire:model.live="adjusted"
                    class="w-4 h-4 rounded border-2 border-primary text-primary" />
                <label for="adjusted" class="text-sm font-medium text-foreground cursor-pointer">Ajustado</label>
            </div>

            @if($data)
                @if($data['cuadra'])
                    <span class="pt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-green-700 bg-green-100 px-3 py-1.5 rounded-full">
                        <x-heroicon-o-check-circle class="w-4 h-4" />
                        Cuadra
                    </span>
                @else
                    <span class="pt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-red-700 bg-red-100 px-3 py-1.5 rounded-full">
                        <x-heroicon-o-exclamation-circle class="w-4 h-4" />
                        DESCUADRE
                    </span>
                @endif
            @endif
        </div>

        @if($data)
            {{-- Tabla Balance de Sumas y Saldos --}}
            <div class="bg-card border border-border rounded-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/40">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Código</th>
                                <th class="px-4 py-3 text-left font-medium text-muted-foreground">Cuenta</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Sumas Debe</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Sumas Haber</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Saldo Deudor</th>
                                <th class="px-4 py-3 text-right font-medium text-muted-foreground">Saldo Acreedor</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach($data['filas'] as $fila)
                                <tr class="hover:bg-muted/20">
                                    <td class="px-4 py-2 font-mono text-sm">{{ $fila['code'] }}</td>
                                    <td class="px-4 py-2">{{ $fila['name'] }}</td>
                                    <td class="px-4 py-2 text-right font-mono">{{ format_money($fila['sumas_debe']) }}</td>
                                    <td class="px-4 py-2 text-right font-mono">{{ format_money($fila['sumas_haber']) }}</td>
                                    <td class="px-4 py-2 text-right font-mono">{{ format_money($fila['saldo_deudor']) }}</td>
                                    <td class="px-4 py-2 text-right font-mono">{{ format_money($fila['saldo_acreedor']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-muted/40 border-t-2 border-border">
                            <tr class="font-semibold">
                                <td colspan="2" class="px-4 py-3 text-right text-sm">Totales</td>
                                <td class="px-4 py-3 text-right font-mono">{{ format_money($data['totales']['sumas_debe']) }}</td>
                                <td class="px-4 py-3 text-right font-mono">{{ format_money($data['totales']['sumas_haber']) }}</td>
                                <td class="px-4 py-3 text-right font-mono">{{ format_money($data['totales']['saldo_deudor']) }}</td>
                                <td class="px-4 py-3 text-right font-mono">{{ format_money($data['totales']['saldo_acreedor']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        @else
            <div class="bg-card border border-border rounded-lg p-10 text-center text-muted-foreground text-sm">
                Seleccione un período contable para ver el Balance de Sumas y Saldos.
            </div>
        @endif

    </div>
</div>
