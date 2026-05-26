<x-app-layout title="Estados Financieros">
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-3">
            <h2 class="font-semibold text-xl text-foreground leading-tight">Estados Financieros</h2>
            <div class="flex items-center gap-2 print:hidden">
                <form method="GET" action="{{ route('finance.statements.index') }}" class="flex items-center gap-2">
                    <input type="date" name="from" value="{{ $from }}" class="rounded-md border-input bg-background text-sm" />
                    <input type="date" name="to" value="{{ $to }}" class="rounded-md border-input bg-background text-sm" />
                    <label class="inline-flex items-center gap-2 text-sm text-foreground border border-input rounded-md px-3 py-2">
                        <input type="checkbox" name="with_taxes" value="1" class="rounded border-input" {{ $withTaxes ? 'checked' : '' }} onchange="this.form.submit()" />
                        Con impuestos (Bolivia)
                    </label>
                    <x-primary-button type="submit">Actualizar</x-primary-button>
                </form>
                <x-secondary-button type="button" onclick="window.print()">
                    <x-heroicon-o-printer class="w-4 h-4 mr-2" />
                    Imprimir
                </x-secondary-button>
            </div>
        </div>
    </x-slot>

    {{-- Print-only header --}}
    <div id="estados-print-header" style="display:none;">
        @php
            $storeName    = \App\Models\Setting::get('store_name', config('app.name'));
            $storeAddress = \App\Models\Setting::get('store_address', '');
            $storePhone   = \App\Models\Setting::get('store_phone', '');
            $logoPath     = \App\Models\Setting::get('shop_logo_path');
        @endphp
        <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #000; padding-bottom:10px; margin-bottom:16px;">
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
                <div style="font-size:13pt; font-weight:bold; text-transform:uppercase;">Estados Financieros</div>
                <div style="font-size:9pt; color:#555; margin-top:4px;">
                    Periodo: {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} — {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}<br>
                    Impreso por: <strong>{{ auth()->user()?->name ?? 'Sistema' }}</strong>
                    el {{ now()->format('d/m/Y H:i') }}
                </div>
            </div>
        </div>
    </div>

    <div class="py-4 space-y-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-card border border-border rounded-lg p-4">
                <h3 class="text-lg font-semibold mb-1">Resumen del periodo</h3>
                <p class="text-sm text-muted-foreground">Desde {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} hasta {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}</p>
                <p class="text-sm mt-1">
                    <span class="font-medium">Modo:</span>
                    {{ $withTaxes ? 'Con impuestos (IVA e IT)' : 'Sin impuestos' }}
                </p>
            </div>

            <div class="bg-card border border-border rounded-lg p-4 break-inside-avoid">
                <h3 class="text-lg font-semibold mb-3">1. Balance General</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                    <div>
                        <p class="font-medium mb-2">Activos</p>
                        <ul class="space-y-1">
                            @forelse($statements['balance_general']['assets'] as $row)
                                @if($row->balance != 0)
                                    <li class="flex justify-between gap-2"><span>{{ $row->code }} - {{ $row->name }}</span><span>@money($row->balance)</span></li>
                                @endif
                            @empty
                                <li>-</li>
                            @endforelse
                        </ul>
                        <p class="mt-2 font-semibold">Total Activo: @money($statements['balance_general']['assets_total'])</p>
                    </div>
                    <div>
                        <p class="font-medium mb-2">Pasivos</p>
                        <ul class="space-y-1">
                            @forelse($statements['balance_general']['liabilities'] as $row)
                                @if($row->balance != 0)
                                    <li class="flex justify-between gap-2"><span>{{ $row->code }} - {{ $row->name }}</span><span>@money($row->balance)</span></li>
                                @endif
                            @empty
                                <li>-</li>
                            @endforelse
                        </ul>
                        <p class="mt-2 font-semibold">Total Pasivo: @money($statements['balance_general']['liabilities_total'])</p>
                    </div>
                    <div>
                        <p class="font-medium mb-2">Patrimonio</p>
                        <ul class="space-y-1">
                            @forelse($statements['balance_general']['equity'] as $row)
                                @if($row->balance != 0)
                                    <li class="flex justify-between gap-2"><span>{{ $row->code }} - {{ $row->name }}</span><span>@money($row->balance)</span></li>
                                @endif
                            @empty
                                <li>-</li>
                            @endforelse
                        </ul>
                        <p class="mt-2 font-semibold">Total Patrimonio: @money($statements['balance_general']['equity_total'])</p>
                    </div>
                </div>
            </div>

            <div class="bg-card border border-border rounded-lg p-4 break-inside-avoid">
                <h3 class="text-lg font-semibold mb-3">2. Estado de Resultados</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                    <div>
                        <p class="font-medium mb-2">Ingresos</p>
                        @forelse($statements['estado_resultados']['income_accounts'] as $row)
                            @if($row->balance != 0)
                                <p class="flex justify-between gap-2"><span>{{ $row->code }} - {{ $row->name }}</span><span>@money($row->balance)</span></p>
                            @endif
                        @empty
                            <p>-</p>
                        @endforelse
                        <p class="mt-2 font-semibold">Total Ingresos: @money($statements['estado_resultados']['income_total'])</p>
                    </div>
                    <div>
                        <p class="font-medium mb-2">Costos</p>
                        @forelse($statements['estado_resultados']['cost_accounts'] as $row)
                            @if($row->balance != 0)
                                <p class="flex justify-between gap-2"><span>{{ $row->code }} - {{ $row->name }}</span><span>@money($row->balance)</span></p>
                            @endif
                        @empty
                            <p>-</p>
                        @endforelse
                        <p class="mt-2 font-semibold">Total Costos: @money($statements['estado_resultados']['cost_total'])</p>
                    </div>
                    <div>
                        <p class="font-medium mb-2">Gastos</p>
                        @forelse($statements['estado_resultados']['expense_accounts'] as $row)
                            @if($row->balance != 0)
                                <p class="flex justify-between gap-2"><span>{{ $row->code }} - {{ $row->name }}</span><span>@money($row->balance)</span></p>
                            @endif
                        @empty
                            <p>-</p>
                        @endforelse
                        <p class="mt-2 font-semibold">Total Gastos: @money($statements['estado_resultados']['expense_total'])</p>
                    </div>
                </div>
                <p class="mt-4 text-base font-bold">Resultado Neto: @money($statements['estado_resultados']['net_result'])</p>
                @if($withTaxes)
                    <div class="mt-3 text-sm border-t border-border pt-3 space-y-1">
                        <p class="font-medium">Impuestos estimados Bolivia</p>
                        <p>IVA débito ({{ number_format($statements['estado_resultados']['taxes']['iva_rate'], 2, '.', ',') }}%): @money($statements['estado_resultados']['taxes']['iva_debito'])</p>
                        <p>IVA crédito: @money($statements['estado_resultados']['taxes']['iva_credito'])</p>
                        <p>IVA determinado: @money($statements['estado_resultados']['taxes']['iva_determinado'])</p>
                        <p>IT ({{ number_format($statements['estado_resultados']['taxes']['it_rate'], 2, '.', ',') }}%): @money($statements['estado_resultados']['taxes']['it_amount'])</p>
                        <p class="font-semibold">Total Impuestos: @money($statements['estado_resultados']['taxes']['total_tax'])</p>
                        <p class="font-bold">Resultado Neto con Impuestos: @money($statements['estado_resultados']['net_result_after_tax'])</p>
                    </div>
                @endif
            </div>

            <div class="bg-card border border-border rounded-lg p-4 break-inside-avoid">
                <h3 class="text-lg font-semibold mb-3">3. Indicadores de Inversion (ROI y TIR)</h3>
                @php($ind = $statements['indicadores_inversion'])
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">
                    <div class="border border-border rounded-md p-3">
                        <p class="text-muted-foreground">Base de inversion</p>
                        <p class="text-lg font-semibold">@money($ind['investment_base'])</p>
                        @if(!empty($ind['opening_balance_date']))
                            <p class="text-xs text-muted-foreground mt-1">Configurada desde {{ \Carbon\Carbon::parse($ind['opening_balance_date'])->format('d/m/Y') }}</p>
                        @endif
                    </div>
                    <div class="border border-border rounded-md p-3">
                        <p class="text-muted-foreground">ROI del periodo</p>
                        <p class="text-lg font-semibold">
                            @if($ind['roi_percent'] !== null)
                                {{ number_format($ind['roi_percent'], 2, '.', ',') }}%
                            @else
                                No disponible
                            @endif
                        </p>
                    </div>
                    <div class="border border-border rounded-md p-3">
                        <p class="text-muted-foreground">TIR estimada</p>
                        <p class="text-lg font-semibold">
                            @if($ind['tir_annual_percent'] !== null)
                                {{ number_format($ind['tir_annual_percent'], 2, '.', ',') }}% anual
                            @else
                                No disponible
                            @endif
                        </p>
                        @if($ind['tir_monthly_percent'] !== null)
                            <p class="text-xs text-muted-foreground mt-1">{{ number_format($ind['tir_monthly_percent'], 2, '.', ',') }}% mensual</p>
                        @endif
                    </div>
                    <div class="border border-border rounded-md p-3">
                        <p class="text-muted-foreground">VAN</p>
                        <p class="text-lg font-semibold">
                            @if($ind['van_amount'] !== null)
                                @money($ind['van_amount'])
                            @else
                                No disponible
                            @endif
                        </p>
                        <p class="text-xs text-muted-foreground mt-1">Tasa anual: {{ number_format($ind['discount_rate_annual'], 2, '.', ',') }}%</p>
                    </div>
                </div>
                <p class="mt-2 text-sm">
                    <span class="font-medium">Periodo de recuperacion:</span>
                    {{ $ind['payback_label'] ?? 'No disponible' }}
                </p>
                <p class="mt-3 text-sm"><span class="font-medium">Interpretacion:</span> {{ $ind['analysis'] }}</p>
            </div>

            <div class="bg-card border border-border rounded-lg p-4 break-inside-avoid">
                <h3 class="text-lg font-semibold mb-3">4. Estado de Resultados Acumulados / Patrimonio</h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">
                    <p><span class="font-medium">Patrimonio Inicial:</span> @money($statements['estado_patrimonio']['opening_equity'])</p>
                    <p><span class="font-medium">Resultado del Periodo:</span> @money($statements['estado_patrimonio']['period_result'])</p>
                    <p><span class="font-medium">Variacion:</span> @money($statements['estado_patrimonio']['changes'])</p>
                    <p><span class="font-medium">Patrimonio Final:</span> @money($statements['estado_patrimonio']['closing_equity'])</p>
                </div>
            </div>

            <div class="bg-card border border-border rounded-lg p-4 break-inside-avoid">
                <h3 class="text-lg font-semibold mb-3">5. Estado de Cambios de la Situacion Financiera / Flujo de Efectivo</h3>
                <div class="text-sm space-y-2">
                    @forelse($statements['flujo_efectivo']['cash_accounts'] as $row)
                        <p class="flex justify-between gap-2">
                            <span>{{ $row->code }} - {{ $row->name }}</span>
                            <span>Entrada: @money($row->inflow) | Salida: @money($row->outflow) | Neto: @money($row->net)</span>
                        </p>
                    @empty
                        <p>-</p>
                    @endforelse
                    <p class="font-semibold mt-2">Entrada Total: @money($statements['flujo_efectivo']['total_inflow'])</p>
                    <p class="font-semibold">Salida Total: @money($statements['flujo_efectivo']['total_outflow'])</p>
                    <p class="font-bold">Variacion Neta de Efectivo: @money($statements['flujo_efectivo']['net_change'])</p>
                </div>
            </div>

            <div class="bg-card border border-border rounded-lg p-4 break-inside-avoid">
                <h3 class="text-lg font-semibold mb-3">6. Notas a los Estados Financieros</h3>
                <ul class="list-disc pl-5 text-sm space-y-1">
                    @foreach($statements['notas'] as $note)
                        <li>{{ $note }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>

    <style>
        /* ===== ESTADOS FINANCIEROS PRINT STYLES ===== */
        @media print {
            @page { size: letter portrait; margin: 1.5cm; }

            /* Hide navigation and UI chrome */
            nav,
            header,
            footer,
            .print\:hidden { display: none !important; }

            /* Show print header */
            #estados-print-header { display: block !important; }

            /* Reset layout */
            body { background: #fff !important; margin: 0 !important; padding: 0 !important; color: #000 !important; }
            .py-4, .max-w-7xl {
                padding: 0 !important;
                max-width: 100% !important;
                margin: 0 !important;
            }
            .bg-card { background: transparent !important; }
            .border, .rounded-lg {
                border: none !important;
                border-radius: 0 !important;
                box-shadow: none !important;
            }
            .sm\:px-6, .lg\:px-8 { padding: 0 !important; }

            /* Section headings */
            h3 {
                font-size: 11pt !important;
                border-bottom: 1px solid #ccc !important;
                padding-bottom: 4px !important;
                margin-bottom: 10px !important;
                margin-top: 0 !important;
            }

            /* Flex layouts in sections */
            .flex.justify-between { display: flex !important; justify-content: space-between !important; }
            .grid { display: grid !important; }

            /* Keep each financial section together */
            .break-inside-avoid {
                break-inside: avoid !important;
                page-break-inside: avoid !important;
                margin-bottom: 18px !important;
                padding: 8px 0 !important;
                border-bottom: 1px solid #e0e0e0 !important;
            }

            /* Indicator cards */
            .border.border-border.rounded-md {
                border: 1px solid #ccc !important;
                padding: 8px !important;
                border-radius: 4px !important;
            }

            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
</x-app-layout>
