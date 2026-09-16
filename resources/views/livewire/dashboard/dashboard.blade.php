@php
    $user = auth()->user();
    $canSeeProfit = $user?->can('purchases.view');
    $canSeeCash = $user?->can('finance.view');
    $kpiCount = 1 + ($canSeeProfit ? 1 : 0) + ($canSeeCash ? 1 : 0);
    $businessTz = \App\Support\BusinessTime::timezone();
@endphp
<div class="space-y-6">

    {{-- Saludo + período --}}
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-foreground">
                {{ $greeting }}{{ $firstName !== '' ? ', ' . $firstName : '' }}
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">Así va tu negocio · {{ $todayLabel }}</p>
        </div>

        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div class="inline-flex flex-wrap gap-0.5 rounded-lg bg-muted p-1" role="group" aria-label="Período">
                @foreach(\App\Livewire\Dashboard\Dashboard::PERIOD_OPTIONS as $period)
                    <button type="button"
                            wire:click="setPeriod('{{ $period->value }}')"
                            aria-pressed="{{ $dateFilter === $period->value ? 'true' : 'false' }}"
                            class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors {{ $dateFilter === $period->value ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground' }}">
                        {{ $period->label() }}
                    </button>
                @endforeach
            </div>

            <div x-show="$wire.dateFilter === 'custom'" x-cloak x-transition
                 x-data="{
                     init() {
                         flatpickr(this.$refs.picker, {
                             mode: 'range',
                             dateFormat: 'Y-m-d',
                             altInput: true,
                             altFormat: 'd/m/Y',
                             defaultDate: [this.$wire.customStartDate, this.$wire.customEndDate],
                             onChange: (selectedDates, dateStr, instance) => {
                                 if (selectedDates.length === 2) {
                                     this.$wire.updateCustomRange(
                                         instance.formatDate(selectedDates[0], 'Y-m-d'),
                                         instance.formatDate(selectedDates[1], 'Y-m-d')
                                     );
                                 }
                             }
                         });
                     }
                 }">
                <input x-ref="picker" type="text" placeholder="Desde – hasta"
                       class="h-9 w-full sm:w-[220px] rounded-md border border-input bg-background px-3 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
            </div>

            <x-heroicon-o-arrow-path wire:loading class="h-4 w-4 animate-spin text-muted-foreground" />
        </div>
    </div>

    {{-- Primeros pasos (emprendedor) --}}
    @if($onboarding)
        <div class="rounded-xl border border-border bg-card p-5">
            <h2 class="text-base font-semibold text-foreground">¡Bienvenido! Arrancá en 3 pasos</h2>
            <ol class="mt-3 grid gap-2 sm:grid-cols-3">
                <li>
                    <a href="{{ route('products.index') }}" class="flex items-center gap-2 rounded-lg border border-border px-3 py-2.5 text-sm font-medium hover:bg-muted">
                        @if($onboarding['products'])
                            <x-heroicon-s-check-circle class="h-5 w-5 shrink-0 text-emerald-600" />
                        @else
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-border text-[11px] font-semibold text-muted-foreground">1</span>
                        @endif
                        Cargá tus productos
                    </a>
                </li>
                <li>
                    <a href="{{ route('sales.create') }}" class="flex items-center gap-2 rounded-lg border border-border px-3 py-2.5 text-sm font-medium hover:bg-muted">
                        @if($onboarding['sales'])
                            <x-heroicon-s-check-circle class="h-5 w-5 shrink-0 text-emerald-600" />
                        @else
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-border text-[11px] font-semibold text-muted-foreground">2</span>
                        @endif
                        Registrá tu primera venta
                    </a>
                </li>
                <li>
                    <a href="{{ route('sales.index') }}" class="flex items-center gap-2 rounded-lg border border-border px-3 py-2.5 text-sm font-medium hover:bg-muted">
                        <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-border text-[11px] font-semibold text-muted-foreground">3</span>
                        Mirá tus ventas
                    </a>
                </li>
            </ol>
        </div>
    @endif

    {{-- Acciones rápidas --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-4">
        @can('sales.create')
            <a href="{{ route('sales.create') }}"
               class="flex h-14 items-center gap-3 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-emerald-700 lg:h-16 lg:px-5 lg:text-base">
                <x-heroicon-o-plus class="h-5 w-5 shrink-0" stroke-width="2.2" />
                Nueva venta
            </a>
        @endcan
        @can('purchases.manage')
            <a href="{{ route('purchases.create') }}"
               class="flex h-14 items-center gap-3 rounded-xl border border-border bg-card px-4 text-sm font-medium text-foreground transition-colors hover:bg-muted lg:h-16 lg:px-5 lg:text-base">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/50 dark:text-blue-400">
                    <x-heroicon-o-shopping-cart class="h-5 w-5" />
                </span>
                Registrar compra
            </a>
        @endcan
        @can('finance.view')
            <a href="{{ route('finance.transactions.index', ['nuevo' => 1]) }}"
               class="flex h-14 items-center gap-3 rounded-xl border border-border bg-card px-4 text-sm font-medium text-foreground transition-colors hover:bg-muted lg:h-16 lg:px-5 lg:text-base">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-violet-50 text-violet-600 dark:bg-violet-950/50 dark:text-violet-400">
                    <x-heroicon-o-credit-card class="h-5 w-5" />
                </span>
                Registrar gasto
            </a>
        @endcan
        @can('products.manage')
            <a href="{{ route('products.index', ['nuevo' => 1]) }}"
               class="flex h-14 items-center gap-3 rounded-xl border border-border bg-card px-4 text-sm font-medium text-foreground transition-colors hover:bg-muted lg:h-16 lg:px-5 lg:text-base">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-950/50 dark:text-amber-400">
                    <x-heroicon-o-cube class="h-5 w-5" />
                </span>
                Nuevo producto
            </a>
        @endcan
    </div>

    {{-- Cifras principales --}}
    <div @class([
        'grid gap-4',
        'sm:grid-cols-2' => $kpiCount >= 2,
        'lg:grid-cols-3' => $kpiCount === 3,
    ])>
        <div class="rounded-xl border border-border bg-card p-5">
            <p class="text-sm font-medium text-muted-foreground">Vendiste</p>
            <p class="mt-1 text-3xl font-bold tracking-tight text-foreground">@money($stats['total_sales'] ?? 0)</p>
            <p class="mt-1 text-sm text-muted-foreground">
                @php $salesCount = (int) ($stats['sales_count'] ?? 0); @endphp
                {{ $salesCount }} {{ $salesCount === 1 ? 'venta' : 'ventas' }}
                @if($this->averageTicket !== null)
                    · ticket promedio @money($this->averageTicket)
                @endif
            </p>
        </div>

        @if($canSeeProfit)
            <div class="rounded-xl border border-border bg-card p-5">
                <p class="text-sm font-medium text-muted-foreground">Ganaste</p>
                <p class="mt-1 text-3xl font-bold tracking-tight {{ ($stats['gross_profit'] ?? 0) < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-400' }}">
                    @money($stats['gross_profit'] ?? 0)
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    @if($this->profitPerHundred !== null)
                        De cada 100 vendidos te quedan {{ $this->profitPerHundred }}
                    @else
                        Aún no hay ventas en este período
                    @endif
                </p>
            </div>
        @endif

        @if($canSeeCash)
            @php $net = (float) ($stats['net_cash_flow'] ?? 0); @endphp
            <div class="rounded-xl border border-border bg-card p-5">
                <p class="text-sm font-medium text-muted-foreground">Movimiento de caja</p>
                <p class="mt-1 text-3xl font-bold tracking-tight {{ $net < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-foreground' }}">
                    {{ $net > 0 ? '+' : '' }}@money($net)
                </p>
                <p class="mt-1 flex flex-wrap gap-x-4 text-sm">
                    <span class="text-emerald-700 dark:text-emerald-400">Entró @money($stats['income'] ?? 0)</span>
                    <span class="text-rose-700 dark:text-rose-400">Salió @money($stats['expense'] ?? 0)</span>
                </p>
            </div>
        @endif
    </div>

    <div class="grid gap-4 lg:grid-cols-3">

        {{-- Ventas de los últimos 7 días --}}
        <div class="rounded-xl border border-border bg-card p-5 lg:col-span-2">
            <div class="flex items-baseline justify-between gap-2">
                <h2 class="text-base font-semibold text-foreground">Ventas de los últimos 7 días</h2>
                @can('sales.view')
                    <a href="{{ route('sales.index') }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-800 dark:text-emerald-400">Ver ventas</a>
                @endcan
            </div>

            <div class="mt-5 flex h-[200px] items-end gap-3 border-b border-border px-1 sm:gap-6">
                @foreach($weekBars as $bar)
                    <div class="group relative flex h-full flex-1 flex-col items-center justify-end"
                         title="{{ $bar['title'] }}: {{ format_money($bar['total']) }}">
                        <span @class([
                            'mb-1.5 whitespace-nowrap text-xs font-semibold text-foreground',
                            'hidden group-hover:block' => ! $bar['is_today'],
                        ])>@money($bar['total'])</span>
                        <div @class([
                                'w-full max-w-[48px] rounded-t transition-colors',
                                'bg-emerald-600' => $bar['is_today'],
                                'bg-emerald-200 group-hover:bg-emerald-300 dark:bg-emerald-900 dark:group-hover:bg-emerald-800' => ! $bar['is_today'],
                             ])
                             style="height: {{ $bar['height'] }}%"
                             role="img"
                             aria-label="{{ $bar['title'] }}: {{ format_money($bar['total']) }}"></div>
                    </div>
                @endforeach
            </div>
            <div class="mt-2 flex gap-3 px-1 text-center text-xs text-muted-foreground sm:gap-6">
                @foreach($weekBars as $bar)
                    <div class="flex-1 {{ $bar['is_today'] ? 'font-semibold text-foreground' : '' }}">{{ $bar['label'] }}</div>
                @endforeach
            </div>
        </div>

        {{-- Necesita tu atención --}}
        <div class="rounded-xl border border-border bg-card p-5">
            <h2 class="text-base font-semibold text-foreground">Necesita tu atención</h2>

            <div class="mt-4 space-y-4">
                @if($periodAlert)
                    @php $critical = $periodAlert['level'] === 'critical'; @endphp
                    <div class="flex gap-3 rounded-lg p-3 {{ $critical ? 'bg-rose-50 dark:bg-rose-950/40' : 'bg-amber-50 dark:bg-amber-950/40' }}">
                        <x-heroicon-o-exclamation-triangle class="mt-0.5 h-5 w-5 shrink-0 {{ $critical ? 'text-rose-600' : 'text-amber-600' }}" />
                        <div class="min-w-0 text-sm">
                            <p class="font-medium {{ $critical ? 'text-rose-900 dark:text-rose-200' : 'text-amber-900 dark:text-amber-200' }}">{{ $periodAlert['message'] }}</p>
                            <a href="{{ route('finance.accounting-periods.index') }}"
                               class="mt-1 inline-block font-semibold underline underline-offset-2 {{ $critical ? 'text-rose-700 dark:text-rose-300' : 'text-amber-700 dark:text-amber-300' }}">
                                {{ $periodAlert['period'] ? 'Ir a períodos contables' : 'Crear período contable' }}
                            </a>
                        </div>
                    </div>
                @endif

                @if($lowStockCount > 0)
                    <div>
                        <p class="text-sm font-medium text-muted-foreground">
                            {{ $lowStockCount }} {{ $lowStockCount === 1 ? 'producto por acabarse' : 'productos por acabarse' }}
                        </p>
                        <ul class="mt-3 space-y-3">
                            @foreach($lowStockProducts as $product)
                                <li class="flex items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium text-foreground" title="{{ $product['name'] }}">{{ $product['name'] }}</p>
                                        <p class="text-xs text-amber-700 dark:text-amber-400">Quedan {{ $product['quantity'] }} · mínimo {{ $product['min_stock'] }}</p>
                                    </div>
                                    @can('purchases.manage')
                                        <a href="{{ route('purchases.create') }}"
                                           class="inline-flex h-8 shrink-0 items-center rounded-md border border-border px-3 text-xs font-medium text-foreground hover:bg-muted">
                                            Reponer
                                        </a>
                                    @endcan
                                </li>
                            @endforeach
                        </ul>
                        @if($lowStockCount > count($lowStockProducts))
                            <a href="{{ route('products.index') }}" class="mt-3 inline-block text-sm font-medium text-amber-700 hover:text-amber-800 dark:text-amber-400">
                                Ver los {{ $lowStockCount }} productos
                            </a>
                        @endif
                    </div>
                @endif

                @if(! $periodAlert && $lowStockCount === 0)
                    <div class="flex flex-col items-center gap-2 py-8 text-center">
                        <x-heroicon-o-check-circle class="h-8 w-8 text-emerald-600" />
                        <p class="text-sm font-medium text-foreground">Todo en orden</p>
                        <p class="text-xs text-muted-foreground">Sin productos por acabarse ni pendientes.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Últimas ventas --}}
    <div class="rounded-xl border border-border bg-card p-5">
        <div class="flex items-baseline justify-between gap-2">
            <h2 class="text-base font-semibold text-foreground">Últimas ventas</h2>
            @can('sales.view')
                <a href="{{ route('sales.index') }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-800 dark:text-emerald-400">Ver todas</a>
            @endcan
        </div>

        @if(count($recentSales) > 0)
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach($recentSales as $sale)
                    @php
                        $saleDate = \Carbon\Carbon::parse($sale['created_at'] ?? $sale['sale_date'])->timezone($businessTz);
                        $when = $saleDate->isSameDay(\Carbon\Carbon::now($businessTz)) ? $saleDate->format('H:i') : $saleDate->format('d/m H:i');
                    @endphp
                    <a href="{{ $user?->can('sales.view') ? route('sales.show', $sale['id']) : '#' }}"
                       class="flex flex-col gap-0.5 rounded-lg bg-muted/60 px-4 py-3 transition-colors hover:bg-muted">
                        <span class="truncate text-sm text-muted-foreground">{{ $when }} · {{ $sale['customer']['name'] ?? 'Cliente general' }}</span>
                        <span class="text-base font-semibold text-foreground">@money($sale['total'])</span>
                    </a>
                @endforeach
            </div>
        @else
            <p class="mt-4 text-sm text-muted-foreground">Todavía no hay ventas registradas.</p>
        @endif
    </div>
</div>
