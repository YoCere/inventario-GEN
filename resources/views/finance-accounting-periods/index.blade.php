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

            {{-- Panel de estado y configuración --}}
            <livewire:accounting-periods.accounting-period-settings />

            {{-- Tabla --}}
            <div>
                <livewire:accounting-periods.accounting-period-table />
            </div>

        </div>
    </div>

    {{-- Formulario modal --}}
    <livewire:accounting-periods.accounting-period-form />
</x-app-layout>
