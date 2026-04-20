<x-app-layout title="Categorías Financieras">
    <x-slot name="header">
        <div class="flex justify-between items-center gap-2">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                {{ __('Categorías Financieras') }}
            </h2>
            <div class="flex items-center gap-2 print:hidden">
                <x-secondary-button type="button" onclick="window.print()">
                    <x-heroicon-o-printer class="w-4 h-4 mr-2" />
                    Imprimir
                </x-secondary-button>
                <x-primary-button x-data x-on:click="$dispatch('create-finance-category')">
                    <x-heroicon-o-plus class="w-4 h-4 mr-2" />
                    Crear Categoría
                </x-primary-button>
            </div>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:finance-categories.finance-category-table />
        </div>
    </div>

    <livewire:finance-categories.finance-category-form />
    <livewire:finance-categories.finance-category-detail />

    <style>
        @media print {
            .print\:hidden { display: none !important; }
        }
    </style>
</x-app-layout>
