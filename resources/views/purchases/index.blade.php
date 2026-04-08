<x-app-layout title="Compras">
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                {{ __('Compras') }}
            </h2>
            <x-primary-button
                x-data
                x-on:click="window.location.href = '{{ route('purchases.create') }}'"
            >
                <x-heroicon-o-plus class="w-4 h-4 mr-2" />
                {{ __('Crear Compra') }}
            </x-primary-button>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:purchases.purchase-table />
        </div>
    </div>

    <livewire:components.delete-modal />
</x-app-layout>