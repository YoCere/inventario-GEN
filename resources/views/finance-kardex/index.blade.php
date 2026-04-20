<x-app-layout title="Kardex valorizado">
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-3">
            <h2 class="font-semibold text-xl text-foreground leading-tight">Kardex valorizado</h2>
            <div class="print:hidden">
                <x-secondary-button type="button" onclick="window.print()">
                    <x-heroicon-o-printer class="w-4 h-4 mr-2" />
                    Imprimir
                </x-secondary-button>
            </div>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-card border border-border rounded-lg p-4">
                <form method="GET" action="{{ route('finance.kardex.index') }}" class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div>
                        <label for="product_id" class="block text-sm font-medium text-foreground mb-1">Producto</label>
                        <select name="product_id" id="product_id" class="w-full rounded-md border-input bg-background text-sm" required>
                            <option value="">Seleccione...</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}" @selected((int) $productId === (int) $product->id)>
                                    {{ $product->name }} ({{ $product->sku }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="from" class="block text-sm font-medium text-foreground mb-1">Desde</label>
                        <input type="date" name="from" id="from" value="{{ $from }}" class="w-full rounded-md border-input bg-background text-sm" required />
                    </div>

                    <div>
                        <label for="to" class="block text-sm font-medium text-foreground mb-1">Hasta</label>
                        <input type="date" name="to" id="to" value="{{ $to }}" class="w-full rounded-md border-input bg-background text-sm" required />
                    </div>

                    <div class="flex items-end">
                        <x-primary-button type="submit" class="w-full justify-center">
                            Generar kardex
                        </x-primary-button>
                    </div>
                </form>

                <p class="text-xs text-muted-foreground mt-3">
                    Metodo aplicado: promedio ponderado movil (entradas por compras recibidas/pagadas y salidas por ventas reservadas/completadas).
                </p>
            </div>

            @if($report)
                <div class="bg-card border border-border rounded-lg p-4">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-4">
                        <div>
                            <h3 class="text-lg font-semibold">{{ $report['product']->name }} ({{ $report['product']->sku }})</h3>
                            <p class="text-sm text-muted-foreground">Periodo: {{ \Carbon\Carbon::parse($report['from'])->format('d/m/Y') }} a {{ \Carbon\Carbon::parse($report['to'])->format('d/m/Y') }}</p>
                        </div>
                        <div class="text-sm">
                            <p><span class="font-medium">Saldo inicial cantidad:</span> {{ number_format($report['opening']['qty'], 0, '.', ',') }}</p>
                            <p><span class="font-medium">Saldo inicial valor:</span> {{ number_format($report['opening']['value'], 2, '.', ',') }}</p>
                            <p><span class="font-medium">Costo promedio inicial:</span> {{ number_format($report['opening']['avg'], 4, '.', ',') }}</p>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm border border-border rounded-md overflow-hidden">
                            <thead class="bg-muted/50">
                                <tr>
                                    <th class="px-3 py-2 text-left">Fecha</th>
                                    <th class="px-3 py-2 text-left">Detalle</th>
                                    <th class="px-3 py-2 text-left">Ref.</th>
                                    <th class="px-3 py-2 text-right">Entrada cant.</th>
                                    <th class="px-3 py-2 text-right">Entrada c/u</th>
                                    <th class="px-3 py-2 text-right">Entrada importe</th>
                                    <th class="px-3 py-2 text-right">Salida cant.</th>
                                    <th class="px-3 py-2 text-right">Salida c/u</th>
                                    <th class="px-3 py-2 text-right">Salida importe</th>
                                    <th class="px-3 py-2 text-right">Saldo cant.</th>
                                    <th class="px-3 py-2 text-right">Saldo c/u</th>
                                    <th class="px-3 py-2 text-right">Saldo importe</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($report['rows'] as $row)
                                    <tr class="border-t border-border">
                                        <td class="px-3 py-2">{{ $row['date'] }}</td>
                                        <td class="px-3 py-2">{{ $row['detail'] }}</td>
                                        <td class="px-3 py-2">{{ $row['reference'] }}</td>
                                        <td class="px-3 py-2 text-right">{{ $row['entry_qty'] ? number_format($row['entry_qty'], 0, '.', ',') : '-' }}</td>
                                        <td class="px-3 py-2 text-right">{{ $row['entry_qty'] ? number_format($row['entry_unit'], 4, '.', ',') : '-' }}</td>
                                        <td class="px-3 py-2 text-right">{{ $row['entry_qty'] ? number_format($row['entry_total'], 2, '.', ',') : '-' }}</td>
                                        <td class="px-3 py-2 text-right">{{ $row['exit_qty'] ? number_format($row['exit_qty'], 0, '.', ',') : '-' }}</td>
                                        <td class="px-3 py-2 text-right">{{ $row['exit_qty'] ? number_format($row['exit_unit'], 4, '.', ',') : '-' }}</td>
                                        <td class="px-3 py-2 text-right">{{ $row['exit_qty'] ? number_format($row['exit_total'], 2, '.', ',') : '-' }}</td>
                                        <td class="px-3 py-2 text-right font-medium">{{ number_format($row['balance_qty'], 0, '.', ',') }}</td>
                                        <td class="px-3 py-2 text-right font-medium">{{ number_format($row['balance_unit'], 4, '.', ',') }}</td>
                                        <td class="px-3 py-2 text-right font-medium">{{ number_format($row['balance_total'], 2, '.', ',') }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="12" class="px-3 py-4 text-center text-muted-foreground">No hay movimientos en el periodo seleccionado.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            <tfoot class="bg-muted/40 border-t border-border">
                                <tr>
                                    <th colspan="3" class="px-3 py-2 text-right">Totales</th>
                                    <th class="px-3 py-2 text-right">{{ number_format($report['totals']['entry_qty'], 0, '.', ',') }}</th>
                                    <th class="px-3 py-2 text-right">-</th>
                                    <th class="px-3 py-2 text-right">{{ number_format($report['totals']['entry_total'], 2, '.', ',') }}</th>
                                    <th class="px-3 py-2 text-right">{{ number_format($report['totals']['exit_qty'], 0, '.', ',') }}</th>
                                    <th class="px-3 py-2 text-right">-</th>
                                    <th class="px-3 py-2 text-right">{{ number_format($report['totals']['exit_total'], 2, '.', ',') }}</th>
                                    <th class="px-3 py-2 text-right">{{ number_format($report['totals']['closing_qty'], 0, '.', ',') }}</th>
                                    <th class="px-3 py-2 text-right">-</th>
                                    <th class="px-3 py-2 text-right">{{ number_format($report['totals']['closing_total'], 2, '.', ',') }}</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>

