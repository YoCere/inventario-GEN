<x-app-layout title="Usuarios">
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                {{ __('Usuarios') }}
            </h2>
            <x-primary-button x-data x-on:click="$dispatch('create-user')">
                <x-heroicon-o-plus class="w-4 h-4 mr-2" />
                {{ __('Crear Usuario') }}
            </x-primary-button>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:users.user-table />
        </div>
    </div>

    <livewire:users.user-form />
    <livewire:users.user-detail />
</x-app-layout>