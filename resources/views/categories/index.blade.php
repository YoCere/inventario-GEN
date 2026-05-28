<x-app-layout title="Categorias">
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row gap-3 items-start sm:items-center">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                {{ __('Categorias') }}
            </h2>
            <x-primary-button x-data x-on:click="$dispatch('create-category')">
                <x-heroicon-o-plus class="w-4 h-4 mr-2" />
                {{ __('Crear categoria') }}
            </x-primary-button>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:categories.category-table />
        </div>
    </div>

    <livewire:categories.category-form />
    <livewire:categories.category-detail />
</x-app-layout>
