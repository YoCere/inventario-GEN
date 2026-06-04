<x-app-layout title="Libro Diario">
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-3">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                Libro Diario
            </h2>
            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-2 print:hidden">
                <form method="GET" action="{{ route('finance.journal-entries.book') }}"
                      class="flex flex-col sm:flex-row items-start sm:items-center gap-2">
                    <input type="date" name="from" value="{{ $from }}"
                           class="w-full sm:w-auto rounded-md border-input bg-background text-sm px-2 py-1.5" />
                    <span class="text-muted-foreground text-sm hidden sm:inline">—</span>
                    <input type="date" name="to" value="{{ $to }}"
                           class="w-full sm:w-auto rounded-md border-input bg-background text-sm px-2 py-1.5" />
                    <x-primary-button type="submit">
                        <x-heroicon-o-funnel class="w-4 h-4 mr-1" />
                        Filtrar
                    </x-primary-button>
                </form>
            </div>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-card border border-border rounded-lg overflow-hidden">

                {{-- ── Encabezado contable ─────────────────────────────────── --}}
                <div class="px-6 py-4 border-b border-border text-center">
                    <p class="text-lg font-bold uppercase tracking-wide">{{ $storeName }}</p>
                    @if($storeAddress)
                        <p class="text-sm text-muted-foreground">{{ $storeAddress }}</p>
                    @endif
                    @if($storePhone)
                        <p class="text-sm text-muted-foreground">Tel. {{ $storePhone }}</p>
                    @endif
                    <p class="mt-2 text-base font-semibold uppercase tracking-widest">Libro Diario</p>
                    <p class="text-xs text-muted-foreground">(Expresado en Bolivianos)</p>
                    <p class="text-xs text-muted-foreground mt-1">
                        Período: {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }}
                        — {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}
                    </p>
                </div>

                {{-- ── Tabla principal ─────────────────────────────────────── --}}
                <div class="overflow-x-auto">
                    <table class="w-full text-sm border-collapse">
                        <thead>
                            <tr class="bg-muted/50 text-xs uppercase text-muted-foreground">
                                <th class="px-3 py-2 text-left border-b border-border w-24">Fecha</th>
                                <th class="px-3 py-2 text-left border-b border-border w-28">Código</th>
                                <th class="px-3 py-2 text-left border-b border-border">Detalle</th>
                                <th class="px-3 py-2 text-right border-b border-border w-32">Debe</th>
                                <th class="px-3 py-2 text-right border-b border-border w-32">Haber</th>
                            </tr>
                        </thead>
                        <tbody>

                            @forelse($rows as $entry)

                                {{-- ── Fila encabezado de comprobante ──────── --}}
                                <tr class="bg-muted/30 font-semibold text-xs">
                                    <td class="px-3 py-1.5 border-b border-border text-nowrap">
                                        {{ $entry['date'] }}
                                    </td>
                                    <td colspan="4" class="px-3 py-1.5 border-b border-border uppercase tracking-wide">
                                        COMPROBANTE DE {{ $entry['voucher_label'] }}
                                        Nro:&nbsp;{{ $entry['voucher_number'] ?? '—' }}
                                    </td>
                                </tr>

                                {{-- ── Líneas del asiento ───────────────────── --}}
                                @foreach($entry['lines'] as $line)
                                    <tr class="hover:bg-muted/20 transition-colors">
                                        <td class="px-3 py-1 border-b border-border/50"></td>
                                        <td class="px-3 py-1 border-b border-border/50 font-mono text-xs">
                                            {{ $line['code'] }}
                                        </td>
                                        <td class="px-3 py-1 border-b border-border/50
                                            {{ $line['debit'] === 0 ? 'pl-8' : '' }}">
                                            {{ $line['name'] }}
                                        </td>
                                        <td class="px-3 py-1 border-b border-border/50 text-right font-mono text-xs tabular-nums">
                                            @if($line['debit'] > 0)
                                                {{ format_money($line['debit']) }}
                                            @endif
                                        </td>
                                        <td class="px-3 py-1 border-b border-border/50 text-right font-mono text-xs tabular-nums">
                                            @if($line['credit'] > 0)
                                                {{ format_money($line['credit']) }}
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach

                                {{-- ── Glosa ────────────────────────────────── --}}
                                @if($entry['glosa'])
                                    <tr>
                                        <td class="px-3 py-1 border-b border-border/50"></td>
                                        <td class="px-3 py-1 border-b border-border/50"></td>
                                        <td colspan="3" class="px-3 py-1 border-b border-border/50 italic text-xs text-muted-foreground">
                                            {{ $entry['glosa'] }}
                                        </td>
                                    </tr>
                                @endif

                                {{-- ── Subtotal del comprobante ─────────────── --}}
                                <tr class="font-semibold text-xs bg-muted/10">
                                    <td class="px-3 py-1.5 border-b-2 border-border"></td>
                                    <td class="px-3 py-1.5 border-b-2 border-border"></td>
                                    <td class="px-3 py-1.5 border-b-2 border-border text-right text-muted-foreground uppercase tracking-wide">
                                        Subtotal
                                    </td>
                                    <td class="px-3 py-1.5 border-b-2 border-border text-right font-mono tabular-nums underline decoration-double">
                                        {{ format_money($entry['subtotal_debit']) }}
                                    </td>
                                    <td class="px-3 py-1.5 border-b-2 border-border text-right font-mono tabular-nums underline decoration-double">
                                        {{ format_money($entry['subtotal_credit']) }}
                                    </td>
                                </tr>

                                {{-- Spacer between entries --}}
                                <tr><td colspan="5" class="py-0.5"></td></tr>

                            @empty
                                <tr>
                                    <td colspan="5" class="px-6 py-12 text-center text-muted-foreground">
                                        No hay asientos contabilizados en el período seleccionado.
                                    </td>
                                </tr>
                            @endforelse

                        </tbody>

                        {{-- ── TOTALES ACUMULADOS ───────────────────────────── --}}
                        @if(count($rows) > 0)
                        <tfoot>
                            <tr class="font-bold text-sm bg-muted/30">
                                <td class="px-3 py-2 border-t-2 border-border"></td>
                                <td class="px-3 py-2 border-t-2 border-border"></td>
                                <td class="px-3 py-2 border-t-2 border-border text-right uppercase tracking-widest">
                                    TOTALES
                                </td>
                                <td class="px-3 py-2 border-t-2 border-border text-right font-mono tabular-nums">
                                    {{ format_money($totalDebit) }}
                                </td>
                                <td class="px-3 py-2 border-t-2 border-border text-right font-mono tabular-nums">
                                    {{ format_money($totalCredit) }}
                                </td>
                            </tr>
                        </tfoot>
                        @endif
                    </table>
                </div>
                {{-- end overflow-x-auto --}}

            </div>
            {{-- end bg-card --}}
        </div>
    </div>

</x-app-layout>
