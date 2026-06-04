<div>
    <div class="max-w-full px-4 sm:px-6 lg:px-8 space-y-4">

        {{-- Controles: Período + cuadre + utilidad --}}
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

                <div class="pt-5 text-sm text-foreground">
                    <span class="text-muted-foreground">Utilidad del período:</span>
                    <span class="font-semibold font-mono ml-1">{{ format_money($data['utilidad']) }}</span>
                </div>
            @endif
        </div>

        @if(session('saved'))
            <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-lg px-4 py-2">
                {{ session('saved') }}
            </div>
        @endif

        @if($data)
            {{-- Tabla Hoja de Trabajo (16 columnas) --}}
            <div class="bg-card border border-border rounded-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs whitespace-nowrap">
                        <thead class="bg-muted/40">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium text-muted-foreground sticky left-0 bg-muted/40 z-10">Código</th>
                                <th class="px-3 py-2 text-left font-medium text-muted-foreground">Cuenta</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Ini.Debe</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Ini.Haber</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Mov.Débito</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Mov.Crédito</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Aj.Débito</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Aj.Crédito</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Saj.Debe</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Saj.Haber</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Res.Debe</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Res.Haber</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Bal.Debe</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Bal.Haber</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">Var%</th>
                                <th class="px-3 py-2 text-right font-medium text-muted-foreground">%Total</th>
                                <th class="px-3 py-2 text-left font-medium text-muted-foreground min-w-64">Acción sugerida</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach($data['filas'] as $f)
                                <tr class="hover:bg-muted/20">
                                    <td class="px-3 py-2 font-mono sticky left-0 bg-card z-10 border-r border-border">{{ $f['code'] }}</td>
                                    <td class="px-3 py-2">{{ $f['name'] }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ format_money($f['saldo_inicial_debe']) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ format_money($f['saldo_inicial_haber']) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ format_money($f['mov_debito']) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ format_money($f['mov_credito']) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ format_money($f['ajuste_debito']) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ format_money($f['ajuste_credito']) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ format_money($f['saldo_aj_debe']) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ format_money($f['saldo_aj_haber']) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ format_money($f['result_debe']) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ format_money($f['result_haber']) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ format_money($f['balance_debe']) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ format_money($f['balance_haber']) }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ $f['variacion_pct'] !== null ? $f['variacion_pct'].'%' : 'N/A' }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ $f['porcentaje_total'] !== null ? $f['porcentaje_total'].'%' : '' }}</td>
                                    <td class="px-3 py-2">
                                        <div class="flex flex-col gap-1">
                                            <span class="text-muted-foreground">{{ $f['suggested_action'] }}</span>
                                            <input type="text" value="{{ $f['manual_note'] }}" placeholder="Nota..."
                                                wire:change="saveNote({{ $f['chart_of_account_id'] }}, $event.target.value, '{{ $f['action_status'] }}')"
                                                class="border border-input rounded px-2 py-1 text-xs w-48 bg-background focus:outline-none focus:ring-1 focus:ring-ring">
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-muted/40 border-t-2 border-border">
                            <tr class="font-semibold">
                                <td colspan="2" class="px-3 py-2 text-right text-xs">Totales</td>
                                <td class="px-3 py-2 text-right font-mono">{{ format_money($data['totales']['saldo_inicial_debe'] ?? 0) }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ format_money($data['totales']['saldo_inicial_haber'] ?? 0) }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ format_money($data['totales']['mov_debito'] ?? 0) }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ format_money($data['totales']['mov_credito'] ?? 0) }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ format_money($data['totales']['ajuste_debito'] ?? 0) }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ format_money($data['totales']['ajuste_credito'] ?? 0) }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ format_money($data['totales']['saldo_aj_debe'] ?? 0) }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ format_money($data['totales']['saldo_aj_haber'] ?? 0) }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ format_money($data['totales']['result_debe'] ?? 0) }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ format_money($data['totales']['result_haber'] ?? 0) }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ format_money($data['totales']['balance_debe'] ?? 0) }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ format_money($data['totales']['balance_haber_con_utilidad'] ?? 0) }}</td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        @else
            <div class="bg-card border border-border rounded-lg p-10 text-center text-muted-foreground text-sm">
                Seleccione un período contable para ver la Hoja Teórica.
            </div>
        @endif

    </div>
</div>
