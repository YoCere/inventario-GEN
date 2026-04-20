<x-app-layout title="Detalle de planilla">
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-3">
            <h2 class="font-semibold text-xl text-foreground leading-tight">Detalle de planilla {{ $sheet->sheet_number }}</h2>
            <div class="flex items-center gap-2">
                <a href="{{ route('finance.payroll.index') }}" class="inline-flex items-center px-3 py-2 rounded-md border border-border text-sm">Volver</a>
                <a href="{{ route('finance.payroll.print', $sheet) }}" target="_blank" class="inline-flex items-center px-3 py-2 rounded-md border border-border text-sm">Imprimir</a>
                @if($sheet->status->value === 'draft')
                    <form method="POST" action="{{ route('finance.payroll.post', $sheet) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center px-4 py-2 rounded-md bg-primary text-primary-foreground text-sm font-medium">
                            Contabilizar planilla
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if(session('success'))
                <div class="rounded-md border border-green-200 bg-green-50 text-green-700 px-4 py-3 text-sm">
                    {{ session('success') }}
                </div>
            @endif

            <div class="bg-card border border-border rounded-lg p-4 grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">
                <p><span class="font-medium">Periodo:</span> {{ $sheet->period_month?->format('m/Y') }}</p>
                <p><span class="font-medium">Fecha pago:</span> {{ $sheet->payment_date?->format('d/m/Y') }}</p>
                <p><span class="font-medium">Estado:</span> {{ $sheet->status->label() }}</p>
                <p><span class="font-medium">Creado por:</span> {{ $sheet->creator?->name ?? '-' }}</p>
                <p><span class="font-medium">Total ganado:</span> {{ format_money($sheet->total_earned) }}</p>
                <p><span class="font-medium">Total descuentos:</span> {{ format_money($sheet->total_deductions) }}</p>
                <p><span class="font-medium">Liquido pagable:</span> {{ format_money($sheet->net_payable) }}</p>
                <p><span class="font-medium">Costo total empleador:</span> {{ format_money($sheet->total_employer_cost) }}</p>
                @if($sheet->journalEntry)
                    <p class="md:col-span-4"><span class="font-medium">Asiento contable:</span> {{ $sheet->journalEntry->entry_number }}</p>
                @endif
            </div>

            <div class="bg-card border border-border rounded-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs">
                        <thead class="bg-muted/50 border-b border-border">
                            <tr>
                                <th class="px-2 py-2 text-left">#</th>
                                <th class="px-2 py-2 text-left">Trabajador</th>
                                <th class="px-2 py-2 text-left">Area</th>
                                <th class="px-2 py-2 text-right">Ganado</th>
                                <th class="px-2 py-2 text-right">Aporte lab.</th>
                                <th class="px-2 py-2 text-right">RC-IVA</th>
                                <th class="px-2 py-2 text-right">Solidario 1%</th>
                                <th class="px-2 py-2 text-right">Solidario 5%</th>
                                <th class="px-2 py-2 text-right">Otros desc.</th>
                                <th class="px-2 py-2 text-right">Total desc.</th>
                                <th class="px-2 py-2 text-right">Liquido</th>
                                <th class="px-2 py-2 text-right">Costo total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($sheet->items as $item)
                                <tr class="border-b border-border">
                                    <td class="px-2 py-2">{{ $item->line_number }}</td>
                                    <td class="px-2 py-2">{{ $item->employee_name }}</td>
                                    <td class="px-2 py-2 uppercase">{{ $item->area }}</td>
                                    <td class="px-2 py-2 text-right">{{ number_format($item->total_earned, 2, '.', ',') }}</td>
                                    <td class="px-2 py-2 text-right">{{ number_format($item->labor_contribution, 2, '.', ',') }}</td>
                                    <td class="px-2 py-2 text-right">{{ number_format($item->rc_iva, 2, '.', ',') }}</td>
                                    <td class="px-2 py-2 text-right">{{ number_format($item->solidarity_1, 2, '.', ',') }}</td>
                                    <td class="px-2 py-2 text-right">{{ number_format($item->solidarity_2, 2, '.', ',') }}</td>
                                    <td class="px-2 py-2 text-right">{{ number_format($item->other_discounts, 2, '.', ',') }}</td>
                                    <td class="px-2 py-2 text-right font-medium">{{ number_format($item->total_deductions, 2, '.', ',') }}</td>
                                    <td class="px-2 py-2 text-right font-medium">{{ number_format($item->net_payable, 2, '.', ',') }}</td>
                                    <td class="px-2 py-2 text-right font-semibold">{{ number_format($item->total_employer_cost, 2, '.', ',') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

