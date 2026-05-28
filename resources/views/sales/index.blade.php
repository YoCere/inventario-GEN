<x-app-layout title="Sales">
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                {{ __('Ventas') }}
            </h2>
            <x-primary-button
                x-data
                x-on:click="window.location.href = '{{ route('sales.create') }}'"
            >
                <x-heroicon-o-plus class="w-4 h-4 mr-2" />
                {{ __('Crear una venta') }}
            </x-primary-button>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:sales.sales-table />
        </div>
    </div>

    <livewire:components.delete-modal />
</x-app-layout>
