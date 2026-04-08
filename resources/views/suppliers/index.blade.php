<x-app-layout title="Proveedores">
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                {{ __('Proveedores') }}
            </h2>
            <x-primary-button x-data x-on:click="$dispatch('create-supplier')">
                <x-heroicon-o-plus class="w-4 h-4 mr-2" />
                {{ __('Crear Proveedor') }}
            </x-primary-button>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:suppliers.supplier-table />
        </div>
    </div>

    <livewire:suppliers.supplier-form />
    <livewire:suppliers.supplier-detail />
</x-app-layout>