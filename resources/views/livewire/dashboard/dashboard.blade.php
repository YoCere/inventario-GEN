<div>
    <div class="space-y-6">
        <!-- Filter Section -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-card p-4 rounded-lg border border-border shadow-sm">
        <div>
            <h2 class="text-lg font-semibold text-foreground">Resumen</h2>
            <p class="text-sm text-muted-foreground">Monitorea el rendimiento de tu negocio de un vistazo.</p>
            @if(auth()->user()?->isAdmin())
                <div class="mt-2 inline-flex items-center gap-2 rounded-md border border-input bg-background px-2 py-1">
                    <span class="text-xs text-muted-foreground">Modo:</span>
                    <button
                        type="button"
                        wire:click="setDisplayMode('percent')"
                        class="rounded px-2 py-1 text-xs {{ $displayMode === 'percent' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted' }}"
                    >
                        Porcentajes
                    </button>
                    <button
                        type="button"
                        wire:click="setDisplayMode('amount')"
                        class="rounded px-2 py-1 text-xs {{ $displayMode === 'amount' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted' }}"
                    >
                        Montos
                    </button>
                </div>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <!-- Period Selector -->
            <select wire:model.live="dateFilter" class="h-9 w-[180px] rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                @foreach(\App\Enums\DatePeriod::cases() as $period)
                    <option value="{{ $period->value }}">{{ $period->label() }}</option>
                @endforeach
            </select>

            <!-- Custom Date Range -->
            <!-- Custom Date Range (Flatpickr) -->
            <div x-show="$wire.dateFilter === 'custom'" x-transition class="flex items-center gap-2"
                 x-data="{
                     init() {
                         flatpickr(this.$refs.picker, {
                             mode: 'range',
                             dateFormat: 'Y-m-d',
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
                 }"
            >
                <input x-ref="picker" type="text" class="h-9 w-[240px] rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring" placeholder="Seleccionar rango de fechas...">
            </div>

             <!-- Refresh Button -->
             <button wire:click="$refresh" class="print:hidden inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 border border-input bg-background hover:bg-accent hover:text-accent-foreground h-9 w-9">
                <x-heroicon-o-arrow-path wire:loading.class="animate-spin" class="h-4 w-4" />
            </button>
            
            <!-- Print Button -->
            <button onclick="window.print()" class="print:hidden inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-primary text-primary-foreground hover:bg-primary/90 h-9 px-4 py-2 gap-2">
                <x-heroicon-o-printer class="h-4 w-4" />
                <span class="hidden sm:inline">Imprimir reporte</span>
            </button>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        <!-- Total Sales (primary / highlighted) -->
        <div class="rounded-xl border border-gray-800 bg-gray-900 text-white shadow-sm">
            <div class="p-4 flex flex-row items-center justify-between space-y-0 pb-2">
                <h3 class="tracking-tight text-sm font-medium text-gray-300">Ventas totales</h3>
                <div class="flex items-center gap-2">
                    @if(auth()->user()?->isAdmin())
                        <button type="button" wire:click="toggleSalesVisibility" class="text-gray-300 hover:text-white" title="Mostrar/ocultar">
                            @if($showSalesTotals)
                                <x-heroicon-o-eye class="h-4 w-4" />
                            @else
                                <x-heroicon-o-eye-slash class="h-4 w-4" />
                            @endif
                        </button>
                    @endif
                    <x-heroicon-o-banknotes class="h-4 w-4 text-gray-400" />
                </div>
            </div>
            <div class="p-4 pt-0">
                <div class="text-xl sm:text-2xl font-bold text-white">
                    @if($displayMode === 'percent')
                        {{ $this->salesToIncomePercent !== null ? number_format($this->salesToIncomePercent, 2, '.', ',') . '%' : 'N/D' }}
                    @else
                        @if(auth()->user()?->isAdmin() && ! $showSalesTotals)
                            ******
                        @else
                            @money($stats['total_sales'] ?? 0)
                        @endif
                    @endif
                </div>
                <p class="text-xs text-gray-400 mt-1">
                    @if($displayMode === 'percent')
                        Participacion de ventas sobre ingresos
                    @else
                        {{ $stats['sales_count'] ?? 0 }} transacciones
                    @endif
                </p>
            </div>
        </div>

        <!-- Net Cash Flow -->
        <div class="rounded-xl border bg-card text-card-foreground shadow-sm">
            <div class="p-4 flex flex-row items-center justify-between space-y-0 pb-2">
                <h3 class="tracking-tight text-sm font-medium">Flujo de caja neto</h3>
                 <x-heroicon-o-currency-dollar class="h-4 w-4 text-muted-foreground" />
            </div>
            <div class="p-4 pt-0">
                <div class="text-xl sm:text-2xl font-bold {{ ($stats['net_cash_flow'] ?? 0) >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                    @if($displayMode === 'percent')
                        {{ $this->netCashFlowPercent !== null ? number_format($this->netCashFlowPercent, 2, '.', ',') . '%' : 'N/D' }}
                    @else
                        @money($stats['net_cash_flow'] ?? 0)
                    @endif
                </div>
                <div class="flex justify-between text-[11px] sm:text-xs text-muted-foreground mt-1">
                    <span class="text-emerald-600 flex items-center gap-1" title="Ingreso total">
                        <x-heroicon-s-arrow-up class="w-3 h-3" /> @money($stats['income'] ?? 0)
                    </span>
                    <span class="text-red-600 flex items-center gap-1" title="Gasto total">
                        <x-heroicon-s-arrow-down class="w-3 h-3" /> @money($stats['expense'] ?? 0)
                    </span>
                </div>
            </div>
        </div>

        <!-- Gross Profit -->
        <div class="rounded-xl border bg-card text-card-foreground shadow-sm">
            <div class="p-4 flex flex-row items-center justify-between space-y-0 pb-2">
                <h3 class="tracking-tight text-sm font-medium">Ganancia bruta</h3>
                <x-heroicon-o-arrow-trending-up class="h-4 w-4 text-muted-foreground" />
            </div>
            <div class="p-4 pt-0">
                <div class="text-xl sm:text-2xl font-bold">
                    @if($displayMode === 'percent')
                        {{ $this->grossProfitMarginPercent !== null ? number_format($this->grossProfitMarginPercent, 2, '.', ',') . '%' : 'N/D' }}
                    @else
                        @money($stats['gross_profit'] ?? 0)
                    @endif
                </div>
                <p class="text-xs text-muted-foreground mt-1">
                    @if($displayMode === 'percent')
                        Margen bruto sobre ventas
                    @else
                        Estimado basado en costo de ventas
                    @endif
                </p>
            </div>
        </div>

         <!-- Low Stock Alert -->
         <div class="rounded-xl border bg-card text-card-foreground shadow-sm">
            <div class="p-4 flex flex-row items-center justify-between space-y-0 pb-2">
                <h3 class="tracking-tight text-sm font-medium">Alerta de stock bajo</h3>
                <x-heroicon-o-exclamation-triangle class="h-4 w-4 text-orange-500" />
            </div>
            <div class="p-4 pt-0">
                <div class="text-xl sm:text-2xl font-bold">
                    {{ count($lowStockProducts) }}
                </div>
                <p class="text-xs text-muted-foreground mt-1">
                    Productos por debajo del mínimo
                </p>
            </div>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        <!-- Sales Trend -->
        <div class="col-span-1 lg:col-span-2 rounded-xl border bg-card text-card-foreground shadow-sm break-inside-avoid">
            <div class="p-4 flex flex-col space-y-1.5 pb-2">
                <h3 class="font-semibold leading-none tracking-tight">Ventas por día</h3>
                <p class="text-xs text-muted-foreground">Resultados de ventas diarias</p>
            </div>
            <div class="p-4 pt-0" wire:ignore>
                <div id="salesChart" class="w-full h-[300px]"></div>
            </div>
        </div>

        <!-- Cash Flow -->
        <div class="col-span-1 rounded-xl border bg-card text-card-foreground shadow-sm break-inside-avoid">
            <div class="p-4 flex flex-col space-y-1.5 pb-2">
                <h3 class="font-semibold leading-none tracking-tight">Ingresos vs Gastos</h3>
                <p class="text-xs text-muted-foreground">Resumen financiero.</p>
            </div>
            <div class="p-4 pt-0" wire:ignore>
                <div id="cashFlowChart" class="w-full h-[250px]"></div>
            </div>
        </div>
    </div>

    <!-- Data Tables Section -->
    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        <!-- Recent Sales -->
        <div class="col-span-1 lg:col-span-2 rounded-xl border bg-card text-card-foreground shadow-sm break-inside-avoid">
            <div class="p-4 flex flex-col space-y-1.5 border-b">
                <h3 class="font-semibold leading-none tracking-tight">Ventas recientes</h3>
                <p class="text-xs text-muted-foreground">Resumen de últimas transacciones.</p>
            </div>
            <div class="p-0">
                <div class="relative w-full overflow-auto max-h-[300px]">
                    <table class="w-full caption-bottom text-sm">
                        <thead class="[&_tr]:border-b sticky top-0 bg-card z-10">
                            <tr class="border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted">
                                <th class="h-10 px-4 text-left align-middle font-medium text-muted-foreground">Factura</th>
                                <th class="h-10 px-4 text-right align-middle font-medium text-muted-foreground">Monto</th>
                            </tr>
                        </thead>
                        <tbody class="[&_tr:last-child]:border-0 bg-transparent">
                            @forelse($recentSales as $sale)
                                <tr class="border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted">
                                    <td class="px-4 py-2 align-middle font-medium">
                                        {{ $sale['invoice_number'] }}
                                        <div class="text-[11px] text-muted-foreground font-normal">{{ $sale['customer']['name'] ?? 'Invitado' }}</div>
                                    </td>
                                    <td class="px-4 py-2 align-middle text-right font-medium text-emerald-600">@money($sale['total'])</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="2" class="p-4 text-center text-muted-foreground">Sin ventas recientes.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Expense Breakdown -->
        <div class="col-span-1 rounded-xl border bg-card text-card-foreground shadow-sm break-inside-avoid">
            <div class="p-4 flex flex-col space-y-1.5 pb-2">
                <h3 class="font-semibold leading-none tracking-tight">Desglose de gastos</h3>
                <p class="text-xs text-muted-foreground">Distribución por categoría.</p>
            </div>
            <div class="p-4 pt-0" wire:ignore>
                <div id="expenseChart" class="w-full h-[250px] flex items-center justify-center"></div>
            </div>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-2">
        <!-- Top Selling Products -->
        <div class="col-span-1 rounded-xl border bg-card text-card-foreground shadow-sm break-inside-avoid">
            <div class="p-4 flex flex-col space-y-1.5 border-b">
                <h3 class="font-semibold leading-none tracking-tight">Productos destacados</h3>
                <p class="text-xs text-muted-foreground">Artículos más vendidos.</p>
            </div>
             <div class="p-4 pt-4 max-h-[300px] overflow-auto">
                <div class="space-y-4">
                    @forelse($topProducts as $product)
                        <div class="flex items-center justify-between">
                            <div class="space-y-1 flex-1">
                                <p class="text-sm font-medium leading-none truncate pr-2" title="{{ $product['product_name'] }}">{{ $product['product_name'] }}</p>
                                <p class="text-[11px] text-muted-foreground">{{ $product['sku'] }}</p>
                            </div>
                            <div class="font-semibold text-sm bg-muted px-2 py-1 rounded-md">
                                {{ $product['total_sold'] }} <span class="text-xs font-normal text-muted-foreground">vendidos</span>
                            </div>
                        </div>
                    @empty
                         <p class="text-xs text-muted-foreground text-center py-2">Sin datos de productos.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Top Customers -->
        <div class="col-span-1 rounded-xl border bg-card text-card-foreground shadow-sm break-inside-avoid">
            <div class="p-4 flex flex-col space-y-1.5 border-b">
                <h3 class="font-semibold leading-none tracking-tight">Mejores clientes</h3>
                <p class="text-xs text-muted-foreground">Por mayor facturación.</p>
            </div>
             <div class="p-4 pt-4 max-h-[300px] overflow-auto">
                <div class="space-y-4">
                    @forelse($topCustomers as $customer)
                        <div class="flex items-center justify-between">
                            <div class="space-y-1 flex-1">
                                <p class="text-sm font-medium leading-none truncate pr-2" title="{{ $customer['customer_name'] }}">{{ $customer['customer_name'] }}</p>
                                <p class="text-[11px] text-muted-foreground">{{ $customer['phone'] }}</p>
                            </div>
                            <div class="font-semibold text-sm text-emerald-600 bg-emerald-50 px-2 py-1 rounded-md whitespace-nowrap">
                                @money($customer['total_spent'])
                            </div>
                        </div>
                    @empty
                         <p class="text-xs text-muted-foreground text-center py-2">Sin datos de clientes.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    @media print {
        @page { size: landscape; margin: 1cm; }
        body { background-color: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .print\:hidden { display: none !important; }
        .bg-card { border: 1px solid #e2e8f0; box-shadow: none !important; }
        .grid { gap: 1rem !important; }
        /* Prevent charts and cards from breaking across pages */
        .break-inside-avoid { break-inside: avoid; page-break-inside: avoid; }
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
    document.addEventListener('livewire:initialized', () => {
        let salesChart = null;
        let cashFlowChart = null;
        
        const currencySymbol = "{{ \App\Models\Setting::get('currency_symbol', 'Rp') }}";
        const currencyPosition = "{{ \App\Models\Setting::get('currency_position', 'left') }}";

        const formatMoney = (val) => {
            let num = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 0 }).format(val);
            return currencyPosition === 'left' ? currencySymbol + ' ' + num : num + ' ' + currencySymbol;
        };

        const initCharts = (data) => {
            // Sales Chart
            const salesOptions = {
                series: [{
                    name: 'Ventas',
                    data: data.sales.data
                }],
                chart: {
                    type: 'area',
                    height: 300,
                    toolbar: { show: false },
                    fontFamily: 'inherit',
                    parentHeightOffset: 0
                },
                dataLabels: { enabled: false },
                stroke: { curve: 'smooth', width: 2 },
                xaxis: {
                    categories: data.sales.labels,
                    axisBorder: { show: false },
                    axisTicks: { show: false },
                    labels: {
                        style: { cssClass: 'text-[10px] text-muted-foreground' }
                    }
                },
                yaxis: {
                    labels: {
                        style: { cssClass: 'text-[10px] text-muted-foreground' }
                    }
                },
                tooltip: {
                    y: {
                        formatter: function (val) {
                             return formatMoney(val);
                        }
                    }
                },
                fill: {
                    type: 'gradient',
                    gradient: {
                        shadeIntensity: 1,
                        opacityFrom: 0.7,
                        opacityTo: 0.2,
                        stops: [0, 90, 100]
                    }
                },
                colors: ['#0ea5e9'], // Sky 500
                tooltip: {
                    y: {
                        formatter: function (val) {
                             return formatMoney(val);
                        }
                    }
                }
            };

            // Cash Flow Chart
            const cashFlowOptions = {
                series: [{
                    name: 'Ingresos',
                    data: data.cashFlow.income
                }, {
                    name: 'Gastos',
                    data: data.cashFlow.expense
                }],
                chart: {
                    type: 'bar',
                    height: 250,
                    toolbar: { show: false },
                    fontFamily: 'inherit',
                    parentHeightOffset: 0
                },
                plotOptions: {
                    bar: {
                        horizontal: false,
                        columnWidth: '55%',
                        endingShape: 'rounded'
                    },
                },
                dataLabels: { enabled: false },
                stroke: { show: true, width: 2, colors: ['transparent'] },
                xaxis: {
                    categories: data.cashFlow.labels,
                    labels: {
                        style: { cssClass: 'text-[10px] text-muted-foreground' }
                    }
                },
                yaxis: {
                    labels: {
                        style: { cssClass: 'text-[10px] text-muted-foreground' },
                        formatter: (val) => {
                             // Shorten detailed numbers for y-axis
                             if (val >= 1000000) return (val / 1000000).toFixed(1) + 'M';
                             if (val >= 1000) return (val / 1000).toFixed(0) + 'k';
                             return val;
                        }
                    }
                },
                colors: ['#10b981', '#ef4444'], // Emerald 500, Red 500
                fill: { opacity: 1 },
                tooltip: {
                    y: {
                        formatter: function (val) {
                             return formatMoney(val);
                        }
                    }
                }
            };

            // Expense Breakdown Chart
            const hasExpenseData = data.expense.series && data.expense.series.length > 0;
            const expenseOptions = {
                series: hasExpenseData ? data.expense.series.map(Number) : [1],
                labels: hasExpenseData ? data.expense.labels : ['Sin datos'],
                chart: {
                    type: 'donut',
                    height: 250,
                    fontFamily: 'inherit',
                    parentHeightOffset: 0
                },
                plotOptions: {
                    pie: {
                        donut: {
                            size: '65%'
                        }
                    }
                },
                dataLabels: { enabled: false },
                colors: hasExpenseData ? ['#ef4444', '#f97316', '#f59e0b', '#84cc16', '#06b6d4', '#6366f1'] : ['#e5e7eb'],
                tooltip: {
                    enabled: hasExpenseData,
                    y: {
                        formatter: function (val) {
                             return formatMoney(val);
                        }
                    }
                },
                legend: {
                    position: 'bottom',
                    offsetY: 0,
                    height: 60,
                }
            };

            if (salesChart) salesChart.destroy();
            if (cashFlowChart) cashFlowChart.destroy();
            if (window.expenseChartInst) window.expenseChartInst.destroy();

            salesChart = new ApexCharts(document.querySelector("#salesChart"), salesOptions);
            salesChart.render();

            cashFlowChart = new ApexCharts(document.querySelector("#cashFlowChart"), cashFlowOptions);
            cashFlowChart.render();
            
            window.expenseChartInst = new ApexCharts(document.querySelector("#expenseChart"), expenseOptions);
            window.expenseChartInst.render();
        };

        // Initial Load
        initCharts({
            sales: @json($salesChart),
            cashFlow: @json($cashFlowChart),
            expense: @json($expenseChart)
        });



        // Listen for server-side updates
        Livewire.on('stats-updated', (data) => {
             initCharts(data[0]); // data is array of args
        });
    });
</script>
</div>
