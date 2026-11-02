<x-app-layout title="Tiempos de carga y venta">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-foreground leading-tight">
            Tiempos de carga y venta
        </h2>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <livewire:reports.work-timings />
        </div>
    </div>
</x-app-layout>
