<div
    x-data="{ tab: 'low-stock' }"
    class="space-y-6"
>
    {{-- ===================================================================
         Filtro de periodo (aplica a más vendidos, sin movimiento, recomendados)
    =================================================================== --}}
    <div class="flex flex-col sm:flex-row sm:items-center gap-3 bg-card border border-border rounded-lg px-4 py-3">
        <span class="text-sm font-medium text-foreground shrink-0">Periodo de análisis:</span>

        <div class="flex flex-wrap gap-2">
            @foreach(['30' => '30 días', '60' => '60 días', '90' => '90 días', '365' => '1 año', 'custom' => 'Personalizado'] as $val => $label)
                <button
                    type="button"
                    wire:click="$set('period', '{{ $val }}')"
                    class="px-3 py-1.5 rounded-md text-xs font-medium border transition-colors
                        {{ $period === $val
                            ? 'bg-primary text-primary-foreground border-primary'
                            : 'bg-background text-foreground border-input hover:bg-accent' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @if($period === 'custom')
            <div class="flex items-center gap-2">
                <input type="date" wire:model.blur="dateFrom"
                    class="rounded-md border border-input bg-background text-sm px-2 py-1.5 text-foreground">
                <span class="text-muted-foreground text-xs">—</span>
                <input type="date" wire:model.blur="dateTo"
                    class="rounded-md border border-input bg-background text-sm px-2 py-1.5 text-foreground">
            </div>
        @else
            <span class="text-xs text-muted-foreground">
                {{ \Carbon\Carbon::parse($dateFrom)->format('d/m/Y') }} — {{ \Carbon\Carbon::parse($dateTo)->format('d/m/Y') }}
            </span>
        @endif
    </div>

    {{-- ===================================================================
         Tabs
    =================================================================== --}}
    <div>
        <nav class="flex gap-1 border-b border-border mb-6 overflow-x-auto">
            @php
                $tabs = [
                    'low-stock'   => ['icon' => '⚠️', 'label' => 'Bajo stock',        'count' => $lowStock->count()],
                    'top-selling' => ['icon' => '🏆', 'label' => 'Más vendidos',       'count' => $topSelling->count()],
                    'no-sales'    => ['icon' => '🕳️', 'label' => 'Sin movimiento',     'count' => $noSales->count()],
                    'recommended' => ['icon' => '🛒', 'label' => 'Recomendados compra','count' => $recommended->count()],
                ];
            @endphp
            @foreach($tabs as $key => $tab)
                <button
                    type="button"
                    @click="tab = '{{ $key }}'"
                    :class="tab === '{{ $key }}'
                        ? 'border-b-2 border-primary text-primary font-semibold'
                        : 'text-muted-foreground hover:text-foreground'"
                    class="flex items-center gap-2 px-4 py-2.5 text-sm whitespace-nowrap transition-colors -mb-px"
                >
                    <span>{{ $tab['icon'] }}</span>
                    <span>{{ $tab['label'] }}</span>
                    <span class="ml-1 inline-flex items-center justify-center min-w-5 h-5 px-1.5 rounded-full text-xs font-bold
                        {{ $tab['count'] > 0 ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground' }}">
                        {{ $tab['count'] }}
                    </span>
                </button>
            @endforeach
        </nav>

        {{-- ---------------------------------------------------------------
             TAB 1: Bajo stock
        --------------------------------------------------------------- --}}
        <div x-show="tab === 'low-stock'" x-cloak>
            @if($lowStock->isEmpty())
                <div class="text-center py-16 text-muted-foreground">
                    <div class="text-4xl mb-3">✅</div>
                    <p class="font-medium">Todos los productos tienen stock suficiente.</p>
                </div>
            @else
                <div class="rounded-lg border border-border overflow-hidden">
                    <div class="bg-amber-50 dark:bg-amber-950/30 border-b border-amber-200 dark:border-amber-900 px-4 py-3">
                        <p class="text-sm text-amber-800 dark:text-amber-300 font-medium">
                            ⚠️ {{ $lowStock->count() }} producto(s) con stock igual o por debajo del mínimo establecido.
                        </p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50">
                                <tr class="text-left text-muted-foreground">
                                    <th class="px-4 py-2.5 font-medium">Producto</th>
                                    <th class="px-4 py-2.5 font-medium">SKU</th>
                                    <th class="px-4 py-2.5 font-medium">Categoría</th>
                                    <th class="px-4 py-2.5 font-medium text-right">Stock actual</th>
                                    <th class="px-4 py-2.5 font-medium text-right">Stock mínimo</th>
                                    <th class="px-4 py-2.5 font-medium text-right">Déficit</th>
                                    <th class="px-4 py-2.5 font-medium text-center">Estado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach($lowStock as $p)
                                    @php $deficit = $p->min_stock - $p->quantity; @endphp
                                    <tr class="hover:bg-muted/30 transition-colors">
                                        <td class="px-4 py-2.5 font-medium text-foreground">{{ $p->name }}</td>
                                        <td class="px-4 py-2.5 text-muted-foreground font-mono text-xs">{{ $p->sku }}</td>
                                        <td class="px-4 py-2.5 text-muted-foreground">{{ $p->category?->name ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-right font-semibold
                                            {{ $p->quantity <= 0 ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400' }}">
                                            {{ $p->quantity }}
                                        </td>
                                        <td class="px-4 py-2.5 text-right text-foreground">{{ $p->min_stock }}</td>
                                        <td class="px-4 py-2.5 text-right text-red-600 dark:text-red-400 font-medium">
                                            {{ $deficit > 0 ? '-'.$deficit : '0' }}
                                        </td>
                                        <td class="px-4 py-2.5 text-center">
                                            @if($p->quantity <= 0)
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400">
                                                    Sin stock
                                                </span>
                                            @else
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                                    Bajo mínimo
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>

        {{-- ---------------------------------------------------------------
             TAB 2: Más vendidos
        --------------------------------------------------------------- --}}
        <div x-show="tab === 'top-selling'" x-cloak>
            @if($topSelling->isEmpty())
                <div class="text-center py-16 text-muted-foreground">
                    <div class="text-4xl mb-3">📭</div>
                    <p class="font-medium">Sin ventas en el periodo seleccionado.</p>
                </div>
            @else
                <div class="rounded-lg border border-border overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50">
                                <tr class="text-left text-muted-foreground">
                                    <th class="px-4 py-2.5 font-medium w-8">#</th>
                                    <th class="px-4 py-2.5 font-medium">Producto</th>
                                    <th class="px-4 py-2.5 font-medium">SKU</th>
                                    <th class="px-4 py-2.5 font-medium text-right">Unidades vendidas</th>
                                    <th class="px-4 py-2.5 font-medium text-right">Ingresos</th>
                                    <th class="px-4 py-2.5 font-medium text-right">Stock actual</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach($topSelling as $i => $item)
                                    <tr class="hover:bg-muted/30 transition-colors">
                                        <td class="px-4 py-2.5">
                                            @if($i === 0)
                                                <span class="text-yellow-500 font-bold">🥇</span>
                                            @elseif($i === 1)
                                                <span class="text-zinc-400 font-bold">🥈</span>
                                            @elseif($i === 2)
                                                <span class="text-amber-600 font-bold">🥉</span>
                                            @else
                                                <span class="text-muted-foreground text-xs">{{ $i + 1 }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 font-medium text-foreground">
                                            {{ $item->product?->name ?? '—' }}
                                        </td>
                                        <td class="px-4 py-2.5 text-muted-foreground font-mono text-xs">
                                            {{ $item->product?->sku ?? '—' }}
                                        </td>
                                        <td class="px-4 py-2.5 text-right font-semibold text-foreground">
                                            {{ number_format($item->total_qty) }}
                                        </td>
                                        <td class="px-4 py-2.5 text-right text-green-600 dark:text-green-400 font-medium">
                                            {{ $currencySymbol }} {{ number_format($item->total_revenue / 100, 2, ',', '.') }}
                                        </td>
                                        <td class="px-4 py-2.5 text-right text-muted-foreground">
                                            {{ $item->product?->quantity ?? '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <p class="text-xs text-muted-foreground mt-2 text-right">
                    Datos vinculados al mismo ranking del dashboard. Periodo: {{ \Carbon\Carbon::parse($dateFrom)->format('d/m/Y') }} — {{ \Carbon\Carbon::parse($dateTo)->format('d/m/Y') }}
                </p>
            @endif
        </div>

        {{-- ---------------------------------------------------------------
             TAB 3: Sin movimiento
        --------------------------------------------------------------- --}}
        <div x-show="tab === 'no-sales'" x-cloak>
            @if($noSales->isEmpty())
                <div class="text-center py-16 text-muted-foreground">
                    <div class="text-4xl mb-3">🎉</div>
                    <p class="font-medium">Todos los productos activos tuvieron al menos una venta en el periodo.</p>
                </div>
            @else
                <div class="rounded-lg border border-border overflow-hidden">
                    <div class="bg-zinc-50 dark:bg-zinc-900/50 border-b border-border px-4 py-3">
                        <p class="text-sm text-muted-foreground font-medium">
                            🕳️ {{ $noSales->count() }} producto(s) activos sin ninguna venta en el periodo. Considera revisar precio, visibilidad o discontinuar.
                        </p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50">
                                <tr class="text-left text-muted-foreground">
                                    <th class="px-4 py-2.5 font-medium">Producto</th>
                                    <th class="px-4 py-2.5 font-medium">SKU</th>
                                    <th class="px-4 py-2.5 font-medium">Categoría</th>
                                    <th class="px-4 py-2.5 font-medium text-right">Stock</th>
                                    <th class="px-4 py-2.5 font-medium text-right">Precio venta</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach($noSales as $p)
                                    <tr class="hover:bg-muted/30 transition-colors">
                                        <td class="px-4 py-2.5 font-medium text-foreground">{{ $p->name }}</td>
                                        <td class="px-4 py-2.5 text-muted-foreground font-mono text-xs">{{ $p->sku }}</td>
                                        <td class="px-4 py-2.5 text-muted-foreground">{{ $p->category?->name ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-right text-foreground">
                                            <span class="{{ $p->quantity <= 0 ? 'text-red-500' : '' }}">{{ $p->quantity }}</span>
                                        </td>
                                        <td class="px-4 py-2.5 text-right text-muted-foreground">
                                            {{ $currencySymbol }} {{ number_format($p->selling_price / 100, 2, ',', '.') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>

        {{-- ---------------------------------------------------------------
             TAB 4: Recomendados para compra
        --------------------------------------------------------------- --}}
        <div x-show="tab === 'recommended'" x-cloak>
            @if($recommended->isEmpty())
                <div class="text-center py-16 text-muted-foreground">
                    <div class="text-4xl mb-3">✅</div>
                    <p class="font-medium">No hay productos que requieran reabastecimiento urgente en este momento.</p>
                </div>
            @else
                <div class="rounded-lg border border-border overflow-hidden">
                    <div class="bg-blue-50 dark:bg-blue-950/30 border-b border-blue-200 dark:border-blue-900 px-4 py-3">
                        <p class="text-sm text-blue-800 dark:text-blue-300 font-medium">
                            🛒 {{ $recommended->count() }} producto(s) con demanda activa y stock bajo. La cantidad sugerida cubre 30 días según velocidad de venta actual.
                        </p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50">
                                <tr class="text-left text-muted-foreground">
                                    <th class="px-4 py-2.5 font-medium">Producto</th>
                                    <th class="px-4 py-2.5 font-medium">Categoría</th>
                                    <th class="px-4 py-2.5 font-medium text-right">Stock</th>
                                    <th class="px-4 py-2.5 font-medium text-right">Mínimo</th>
                                    <th class="px-4 py-2.5 font-medium text-right">Vendidos</th>
                                    <th class="px-4 py-2.5 font-medium text-right">Días de stock</th>
                                    <th class="px-4 py-2.5 font-medium text-right">Sugerido comprar</th>
                                    <th class="px-4 py-2.5 font-medium text-right">Costo estimado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach($recommended as $p)
                                    <tr class="hover:bg-muted/30 transition-colors">
                                        <td class="px-4 py-2.5">
                                            <div class="font-medium text-foreground">{{ $p->name }}</div>
                                            <div class="text-xs text-muted-foreground font-mono">{{ $p->sku }}</div>
                                        </td>
                                        <td class="px-4 py-2.5 text-muted-foreground">{{ $p->category }}</td>
                                        <td class="px-4 py-2.5 text-right">
                                            <span class="font-semibold {{ $p->quantity <= 0 ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400' }}">
                                                {{ $p->quantity }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-2.5 text-right text-muted-foreground">{{ $p->min_stock }}</td>
                                        <td class="px-4 py-2.5 text-right text-foreground">{{ $p->total_sold }}</td>
                                        <td class="px-4 py-2.5 text-right">
                                            @if($p->days_of_stock !== null)
                                                <span class="font-medium {{ $p->days_of_stock <= 7 ? 'text-red-600 dark:text-red-400' : ($p->days_of_stock <= 14 ? 'text-amber-600 dark:text-amber-400' : 'text-foreground') }}">
                                                    {{ $p->days_of_stock }} d
                                                </span>
                                            @else
                                                <span class="text-muted-foreground">—</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 text-right">
                                            <span class="inline-flex items-center justify-center px-2.5 py-1 rounded-md bg-primary/10 text-primary font-bold text-sm">
                                                {{ $p->suggested }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-2.5 text-right text-muted-foreground">
                                            {{ $currencySymbol }} {{ number_format($p->purchase_cost / 100, 2, ',', '.') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-muted/30 border-t border-border">
                                <tr>
                                    <td colspan="7" class="px-4 py-2.5 text-right text-sm font-semibold text-foreground">
                                        Costo total estimado:
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-bold text-foreground">
                                        {{ $currencySymbol }} {{ number_format($recommended->sum('purchase_cost') / 100, 2, ',', '.') }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            @endif
        </div>

    </div>{{-- /tabs --}}

    {{-- Botón imprimir: usa $wire para leer valores actuales sincronizados --}}
    <div class="flex justify-end mt-2"
         x-data="{
             openPrint() {
                 const from = $wire.dateFrom;
                 const to   = $wire.dateTo;
                 window.open('{{ route('products.report.print') }}?from=' + from + '&to=' + to, '_blank');
             }
         }"
    >
        <button
            type="button"
            @click="openPrint()"
            class="inline-flex items-center gap-2 px-4 py-2 rounded-md border border-input bg-background text-sm font-medium hover:bg-accent hover:text-accent-foreground transition-colors"
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
            </svg>
            Imprimir informe
        </button>
    </div>

    {{-- Loading overlay --}}
    <div wire:loading.flex class="fixed inset-0 bg-background/60 backdrop-blur-sm z-50 items-center justify-center">
        <div class="bg-card border border-border rounded-lg px-6 py-4 flex items-center gap-3 shadow-lg">
            <svg class="animate-spin h-5 w-5 text-primary" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <span class="text-sm font-medium text-foreground">Calculando informe…</span>
        </div>
    </div>

</div>
