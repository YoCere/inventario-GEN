<x-app-layout title="Periodos Contables">
    <x-slot name="header">
        <div class="flex justify-between items-center gap-2">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                Periodos Contables
            </h2>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">

            {{-- Aviso: si no hay un periodo futuro abierto --}}
            @php
                $openCount = \App\Models\AccountingPeriod::where('status', 'open')->count();
                $latestEnd = \App\Models\AccountingPeriod::orderByDesc('end_date')->value('end_date');
                $needsNextPeriod = $latestEnd && \Carbon\Carbon::parse($latestEnd)->diffInDays(now(), false) > -60;
            @endphp

            @if($openCount === 1 && $needsNextPeriod)
                <div class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-amber-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    <div>
                        <p class="font-semibold text-amber-800 text-sm">El periodo actual está próximo a vencer</p>
                        <p class="text-amber-700 text-sm mt-1">
                            Cuando el periodo actual venza, el sistema no podrá registrar ventas, compras ni planillas hasta que exista un nuevo periodo abierto.
                            Crea el próximo periodo contable ahora para evitar interrupciones.
                        </p>
                    </div>
                </div>
            @endif

            @if($openCount === 0)
                <div class="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-red-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    <div>
                        <p class="font-semibold text-red-800 text-sm">¡No hay ningún periodo contable abierto!</p>
                        <p class="text-red-700 text-sm mt-1">
                            El sistema no puede registrar ventas, compras ni planillas. Crea un nuevo periodo contable de inmediato.
                        </p>
                    </div>
                </div>
            @endif

            {{-- Tabla --}}
            <div>
                <livewire:accounting-periods.accounting-period-table />
            </div>

        </div>
    </div>

    {{-- Formulario modal --}}
    <livewire:accounting-periods.accounting-period-form />
</x-app-layout>
