<x-app-layout title="Estados Financieros">
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-3">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                {{ __('Estados Financieros') }}
            </h2>
            <form method="GET" action="{{ route('finance.statements.index') }}" class="flex items-center gap-2">
                <input type="date" name="from" value="{{ $from }}" class="rounded-md border-input bg-background text-sm" />
                <input type="date" name="to" value="{{ $to }}" class="rounded-md border-input bg-background text-sm" />
                <x-primary-button type="submit">Actualizar</x-primary-button>
            </form>
        </div>
    </x-slot>

    <div class="py-4 space-y-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="bg-card border border-border rounded-lg p-4">
                <h3 class="text-lg font-semibold mb-3">1. Balance General</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                    <div>
                        <p class="font-medium mb-2">Activos</p>
                        <ul class="space-y-1">
                            @foreach($statements['balance_general']['assets'] as $row)
                                <li class="flex justify-between gap-2"><span>{{ $row->code }} - {{ $row->name }}</span><span>@money($row->balance)</span></li>
                            @endforeach
                        </ul>
                        <p class="mt-2 font-semibold">Total Activo: @money($statements['balance_general']['assets_total'])</p>
                    </div>
                    <div>
                        <p class="font-medium mb-2">Pasivos</p>
                        <ul class="space-y-1">
                            @foreach($statements['balance_general']['liabilities'] as $row)
                                <li class="flex justify-between gap-2"><span>{{ $row->code }} - {{ $row->name }}</span><span>@money($row->balance)</span></li>
                            @endforeach
                        </ul>
                        <p class="mt-2 font-semibold">Total Pasivo: @money($statements['balance_general']['liabilities_total'])</p>
                    </div>
                    <div>
                        <p class="font-medium mb-2">Patrimonio</p>
                        <ul class="space-y-1">
                            @foreach($statements['balance_general']['equity'] as $row)
                                <li class="flex justify-between gap-2"><span>{{ $row->code }} - {{ $row->name }}</span><span>@money($row->balance)</span></li>
                            @endforeach
                        </ul>
                        <p class="mt-2 font-semibold">Total Patrimonio: @money($statements['balance_general']['equity_total'])</p>
                    </div>
                </div>
            </div>

            <div class="bg-card border border-border rounded-lg p-4">
                <h3 class="text-lg font-semibold mb-3">2. Estado de Resultados</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                    <div>
                        <p class="font-medium mb-2">Ingresos</p>
                        @foreach($statements['estado_resultados']['income_accounts'] as $row)
                            <p class="flex justify-between gap-2"><span>{{ $row->code }} - {{ $row->name }}</span><span>@money($row->balance)</span></p>
                        @endforeach
                        <p class="mt-2 font-semibold">Total Ingresos: @money($statements['estado_resultados']['income_total'])</p>
                    </div>
                    <div>
                        <p class="font-medium mb-2">Costos</p>
                        @foreach($statements['estado_resultados']['cost_accounts'] as $row)
                            <p class="flex justify-between gap-2"><span>{{ $row->code }} - {{ $row->name }}</span><span>@money($row->balance)</span></p>
                        @endforeach
                        <p class="mt-2 font-semibold">Total Costos: @money($statements['estado_resultados']['cost_total'])</p>
                    </div>
                    <div>
                        <p class="font-medium mb-2">Gastos</p>
                        @foreach($statements['estado_resultados']['expense_accounts'] as $row)
                            <p class="flex justify-between gap-2"><span>{{ $row->code }} - {{ $row->name }}</span><span>@money($row->balance)</span></p>
                        @endforeach
                        <p class="mt-2 font-semibold">Total Gastos: @money($statements['estado_resultados']['expense_total'])</p>
                    </div>
                </div>
                <p class="mt-4 text-base font-bold">Resultado Neto: @money($statements['estado_resultados']['net_result'])</p>
            </div>

            <div class="bg-card border border-border rounded-lg p-4">
                <h3 class="text-lg font-semibold mb-3">3. Estado de Resultados Acumulados / Patrimonio</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                    <p><span class="font-medium">Patrimonio Inicial:</span> @money($statements['estado_patrimonio']['opening_equity'])</p>
                    <p><span class="font-medium">Resultado del Periodo:</span> @money($statements['estado_patrimonio']['period_result'])</p>
                    <p><span class="font-medium">Patrimonio Final:</span> @money($statements['estado_patrimonio']['closing_equity'])</p>
                </div>
            </div>

            <div class="bg-card border border-border rounded-lg p-4">
                <h3 class="text-lg font-semibold mb-3">4. Estado de Cambios de la Situación Financiera / Flujo de Efectivo</h3>
                <div class="text-sm space-y-2">
                    @foreach($statements['flujo_efectivo']['cash_accounts'] as $row)
                        <p class="flex justify-between gap-2">
                            <span>{{ $row->code }} - {{ $row->name }}</span>
                            <span>Entrada: @money($row->inflow) | Salida: @money($row->outflow) | Neto: @money($row->net)</span>
                        </p>
                    @endforeach
                    <p class="font-semibold mt-2">Entrada Total: @money($statements['flujo_efectivo']['total_inflow'])</p>
                    <p class="font-semibold">Salida Total: @money($statements['flujo_efectivo']['total_outflow'])</p>
                    <p class="font-bold">Variación Neta de Efectivo: @money($statements['flujo_efectivo']['net_change'])</p>
                </div>
            </div>

            <div class="bg-card border border-border rounded-lg p-4">
                <h3 class="text-lg font-semibold mb-3">5. Notas a los Estados Financieros</h3>
                <ul class="list-disc pl-5 text-sm space-y-1">
                    @foreach($statements['notas'] as $note)
                        <li>{{ $note }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
