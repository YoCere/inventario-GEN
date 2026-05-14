<x-app-layout title="Ubicaciones">
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                {{ __('Ubicaciones') }}
            </h2>
            @if(auth()->user()->isAdmin())
                <x-primary-button x-data x-on:click="$dispatch('create-location')">
                    <x-heroicon-o-plus class="w-4 h-4 mr-2" />
                    {{ __('Crear ubicación') }}
                </x-primary-button>
            @endif
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:locations.location-table />
        </div>
    </div>

    <livewire:locations.location-form />
</x-app-layout>
