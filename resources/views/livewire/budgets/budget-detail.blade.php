<div class="space-y-8">

    {{-- ===================================================
         Cabecera del presupuesto
    =================================================== --}}
    <div class="flex justify-between items-start">
        <div>
            <h3 class="text-lg font-semibold text-foreground">{{ $budget->name }}</h3>
            <p class="text-sm text-muted-foreground">
                Período base: {{ $budget->base_from->format('d/m/Y') }} – {{ $budget->base_to->format('d/m/Y') }}
                &bull; {{ $budget->years }} años &bull; Crecimiento global: {{ $budget->growth_pct }}%
            </p>
        </div>
        <a href="{{ route('finance.budgets.index') }}" class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
            </svg>
            Volver a presupuestos
        </a>
    </div>

    {{-- ===================================================
         Panel de indicadores
    =================================================== --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="rounded-lg border border-border bg-card p-4 text-center">
            <p class="text-xs text-muted-foreground uppercase tracking-wide">VAN</p>
            @if($indicators['van'] !== null)
                <p class="mt-1 text-lg font-bold {{ $indicators['van'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                    {{ format_money($indicators['van']) }}
                </p>
            @else
                <p class="mt-1 text-lg font-bold text-muted-foreground">—</p>
            @endif
        </div>

        <div class="rounded-lg border border-border bg-card p-4 text-center">
            <p class="text-xs text-muted-foreground uppercase tracking-wide">TIR</p>
            @if($indicators['tir_annual_pct'] !== null)
                <p class="mt-1 text-lg font-bold text-foreground">{{ $indicators['tir_annual_pct'] }}%</p>
            @else
                <p class="mt-1 text-lg font-bold text-muted-foreground">—</p>
            @endif
        </div>

        <div class="rounded-lg border border-border bg-card p-4 text-center">
            <p class="text-xs text-muted-foreground uppercase tracking-wide">Payback</p>
            @if($indicators['payback_years'] !== null)
                <p class="mt-1 text-lg font-bold text-foreground">{{ $indicators['payback_years'] }} años</p>
            @else
                <p class="mt-1 text-lg font-bold text-muted-foreground">—</p>
            @endif
        </div>

        <div class="rounded-lg border border-border bg-card p-4 text-center">
            <p class="text-xs text-muted-foreground uppercase tracking-wide">B/C</p>
            @if($indicators['benefit_cost_ratio'] !== null)
                <p class="mt-1 text-lg font-bold {{ $indicators['benefit_cost_ratio'] >= 1 ? 'text-green-600' : 'text-red-600' }}">
                    {{ $indicators['benefit_cost_ratio'] }}
                </p>
            @else
                <p class="mt-1 text-lg font-bold text-muted-foreground">—</p>
            @endif
        </div>
    </div>

    {{-- ===================================================
         Tabla de proyección año × concepto
    =================================================== --}}
    <div>
        <h4 class="text-base font-semibold text-foreground mb-3">Proyección plurianual</h4>
        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="min-w-full divide-y divide-border text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-muted-foreground">Concepto</th>
                        @foreach($project['years'] as $yr)
                            <th class="px-4 py-3 text-right font-semibold text-muted-foreground">Año {{ $yr['year'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-border bg-card">
                    @php
                        $rows = [
                            'income'           => 'Ingresos',
                            'cost'             => 'Costos',
                            'expense'          => 'Gastos',
                            'operating_profit' => 'Utilidad operativa',
                            'iue'              => 'IUE',
                            'net_flow'         => 'Flujo neto',
                        ];
                    @endphp
                    @foreach($rows as $key => $label)
                        <tr class="{{ in_array($key, ['operating_profit','net_flow']) ? 'font-semibold bg-muted/30' : '' }}">
                            <td class="px-4 py-2.5 text-left text-foreground whitespace-nowrap">{{ $label }}</td>
                            @foreach($project['years'] as $yr)
                                <td class="px-4 py-2.5 text-right tabular-nums
                                    {{ $key === 'net_flow' && $yr[$key] < 0 ? 'text-red-600' : '' }}
                                    {{ $key === 'net_flow' && $yr[$key] >= 0 ? 'text-green-700' : '' }}">
                                    {{ format_money($yr[$key]) }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ===================================================
         Tabla de líneas editables
    =================================================== --}}
    @if(auth()->user()->isAdmin())
    <div>
        <h4 class="text-base font-semibold text-foreground mb-3">Líneas del presupuesto <span class="text-xs font-normal text-muted-foreground">(edición en línea)</span></h4>
        @if($budget->lines->isEmpty())
            <p class="text-sm text-muted-foreground italic">Sin líneas. El período base no tiene movimientos contables para sembrar.</p>
        @else
        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="min-w-full divide-y divide-border text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-muted-foreground">Cuenta</th>
                        <th class="px-4 py-3 text-left font-semibold text-muted-foreground">Nombre</th>
                        <th class="px-4 py-3 text-left font-semibold text-muted-foreground">Tipo</th>
                        <th class="px-4 py-3 text-right font-semibold text-muted-foreground">Monto base (Bs)</th>
                        <th class="px-4 py-3 text-right font-semibold text-muted-foreground">Crecimiento (%)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border bg-card">
                    @foreach($budget->lines as $line)
                    <tr>
                        <td class="px-4 py-2 text-foreground font-mono">{{ $line->chart_of_account_code }}</td>
                        <td class="px-4 py-2 text-foreground">{{ $line->name }}</td>
                        <td class="px-4 py-2">
                            @php
                                $typeColors = [
                                    'income'  => 'bg-green-100 text-green-800',
                                    'cost'    => 'bg-red-100 text-red-800',
                                    'expense' => 'bg-amber-100 text-amber-800',
                                ];
                                $typeLabels = ['income' => 'Ingreso', 'cost' => 'Costo', 'expense' => 'Gasto'];
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $typeColors[$line->line_type] ?? 'bg-gray-100 text-gray-800' }}">
                                {{ $typeLabels[$line->line_type] ?? $line->line_type }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                value="{{ $line->base_amount / 100 }}"
                                wire:change="updateLine({{ $line->id }}, 'base_amount', $event.target.value)"
                                class="w-32 text-right rounded border border-input bg-background px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
                            />
                        </td>
                        <td class="px-4 py-2 text-right">
                            <input
                                type="number"
                                step="0.01"
                                value="{{ $line->growth_pct }}"
                                placeholder="Global"
                                wire:change="updateLine({{ $line->id }}, 'growth_pct', $event.target.value)"
                                class="w-24 text-right rounded border border-input bg-background px-2 py-1 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
                            />
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    @endif

    {{-- ===================================================
         Presupuesto vs Real (Año 1)
    =================================================== --}}
    <div>
        <h4 class="text-base font-semibold text-foreground mb-3">Presupuesto vs Real — Año 1</h4>
        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="min-w-full divide-y divide-border text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-muted-foreground">Concepto</th>
                        <th class="px-4 py-3 text-right font-semibold text-muted-foreground">Proyectado</th>
                        <th class="px-4 py-3 text-right font-semibold text-muted-foreground">Real</th>
                        <th class="px-4 py-3 text-right font-semibold text-muted-foreground">Variación</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border bg-card">
                    @php
                        $vsRows = [
                            ['label' => 'Ingresos', 'proj' => 'projected_income',  'act' => 'actual_income',  'var' => 'variance_income'],
                            ['label' => 'Costos',   'proj' => 'projected_cost',    'act' => 'actual_cost',    'var' => 'variance_cost'],
                            ['label' => 'Gastos',   'proj' => 'projected_expense', 'act' => 'actual_expense', 'var' => 'variance_expense'],
                        ];
                    @endphp
                    @foreach($vsRows as $row)
                    <tr>
                        <td class="px-4 py-2.5 text-foreground font-medium">{{ $row['label'] }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ format_money($vsActual['totals'][$row['proj']]) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ format_money($vsActual['totals'][$row['act']]) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums {{ $vsActual['totals'][$row['var']] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            {{ format_money($vsActual['totals'][$row['var']]) }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

</div>
