<x-app-layout title="Planillas de sueldo">
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
            <h2 class="font-semibold text-xl text-foreground leading-tight">Planillas de sueldo</h2>
            <a href="{{ route('users.payroll.create') }}" class="inline-flex items-center px-4 py-2 rounded-md bg-primary text-primary-foreground text-sm font-medium">
                Nueva planilla
            </a>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 rounded-md border border-green-200 bg-green-50 text-green-700 px-4 py-3 text-sm">
                    {{ session('success') }}
                </div>
            @endif

            <div class="bg-card border border-border rounded-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-muted/50 border-b border-border">
                            <tr>
                                <th class="px-4 py-3 text-left">Numero</th>
                                <th class="px-4 py-3 text-left">Periodo</th>
                                <th class="px-4 py-3 text-left">Fecha pago</th>
                                <th class="px-4 py-3 text-right">Trabajadores</th>
                                <th class="px-4 py-3 text-right">Total ganado</th>
                                <th class="px-4 py-3 text-right">Costo total</th>
                                <th class="px-4 py-3 text-left">Estado</th>
                                <th class="px-4 py-3 text-left">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($sheets as $sheet)
                                <tr class="border-b border-border">
                                    <td class="px-4 py-3 font-medium">{{ $sheet->sheet_number }}</td>
                                    <td class="px-4 py-3">{{ $sheet->period_month?->format('m/Y') }}</td>
                                    <td class="px-4 py-3">{{ $sheet->payment_date?->format('d/m/Y') }}</td>
                                    <td class="px-4 py-3 text-right">{{ number_format($sheet->items_count, 0, '.', ',') }}</td>
                                    <td class="px-4 py-3 text-right">{{ format_money($sheet->total_earned) }}</td>
                                    <td class="px-4 py-3 text-right">{{ format_money($sheet->total_employer_cost) }}</td>
                                    <td class="px-4 py-3">{{ $sheet->status->label() }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex gap-2">
                                            <a href="{{ route('users.payroll.show', $sheet) }}" class="text-blue-600 hover:underline">Ver</a>
                                            <a href="{{ route('users.payroll.print', $sheet) }}" target="_blank" class="text-indigo-600 hover:underline">Imprimir</a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-4 py-6 text-center text-muted-foreground">No hay planillas registradas.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-4 py-3 border-t border-border">
                    {{ $sheets->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

