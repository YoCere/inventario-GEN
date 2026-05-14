<x-app-layout title="Almacenes">
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                {{ __('Almacenes') }}
            </h2>
            @if(auth()->user()->isAdmin())
                <x-primary-button x-data x-on:click="$dispatch('create-warehouse')">
                    <x-heroicon-o-plus class="w-4 h-4 mr-2" />
                    {{ __('Crear almacén') }}
                </x-primary-button>
            @endif
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:warehouses.warehouse-table />
        </div>
    </div>

    <livewire:warehouses.warehouse-form />
</x-app-layout>
