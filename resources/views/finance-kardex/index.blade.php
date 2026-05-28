<x-app-layout title="Kardex valorizado">
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-3">
            <h2 class="font-semibold text-xl text-foreground leading-tight print:hidden">Kardex valorizado</h2>
            <div class="print:hidden">
                @if($report)
                <x-secondary-button type="button"
                    onclick="window.open('{{ route('finance.kardex.print', ['product_id' => $productId, 'from' => $from, 'to' => $to]) }}', '_blank')">
                    <x-heroicon-o-printer class="w-4 h-4 mr-2" />
                    Imprimir
                </x-secondary-button>
                @else
                <x-secondary-button type="button" onclick="alert('Genere el kardex primero seleccionando un producto.')">
                    <x-heroicon-o-printer class="w-4 h-4 mr-2" />
                    Imprimir
                </x-secondary-button>
                @endif
            </div>
        </div>
    </x-slot>

    {{-- Print-only header (hidden on screen, shown during print) --}}
    <div id="kardex-print-header" style="display:none;">
        @php
            $storeName    = \App\Models\Setting::get('store_name', config('app.name'));
            $storeAddress = \App\Models\Setting::get('store_address', '');
            $storePhone   = \App\Models\Setting::get('store_phone', '');
            $logoPath     = \App\Models\Setting::get('shop_logo_path');
        @endphp
        <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #000; padding-bottom:10px; margin-bottom:12px;">
            <div style="display:flex; align-items:center; gap:10px;">
                @if($logoPath)
                    <img src="{{ \Illuminate\Support\Facades\Storage::url($logoPath) }}" style="height:50px; object-fit:contain;">
                @endif
                <div>
                    <div style="font-size:16pt; font-weight:bold; text-transform:uppercase;">{{ $storeName }}</div>
                    @if($storeAddress)<div style="font-size:9pt; color:#555;">{{ $storeAddress }}</div>@endif
                    @if($storePhone)<div style="font-size:9pt; color:#555;">Tel. {{ $storePhone }}</div>@endif
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:13pt; font-weight:bold; text-transform:uppercase;">Kardex Valorizado</div>
                <div style="font-size:9pt; color:#555; margin-top:4px;">
                    Impreso por: <strong>{{ auth()->user()?->name ?? 'Sistema' }}</strong><br>
                    el {{ now()->format('d/m/Y H:i') }}
                </div>
            </div>
        </div>
    </div>

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
    <style>
        /* ===== KARDEX PRINT STYLES ===== */
        @media print {
            @page { size: letter landscape; margin: 1cm; }

            /* Hide navigation and UI chrome */
            nav,
            header,
            footer,
            .print\:hidden,
            form { display: none !important; }

            /* Show the print header */
            #kardex-print-header { display: block !important; }

            /* Reset layout */
            body { background: #fff !important; padding: 0 !important; margin: 0 !important; }
            .py-4 { padding: 0 !important; }
            .max-w-7xl { max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
            .bg-card, .border, .rounded-lg {
                background: transparent !important;
                border: none !important;
                border-radius: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
            }
            .sm\:px-6, .lg\:px-8 { padding: 0 !important; }

            /* Compact table for 12 columns */
            table { font-size: 7pt !important; border-collapse: collapse !important; width: 100% !important; }
            th, td { padding: 3px 4px !important; border: 1px solid #999 !important; white-space: nowrap !important; }
            thead { background: #f0f0f0 !important; }
        }
    </style>
</x-app-layout>

