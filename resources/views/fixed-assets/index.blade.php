<x-app-layout title="Activos Fijos">
    <x-slot name="header">
        <div class="flex justify-between items-center gap-2">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                {{ __('Activos Fijos') }}
            </h2>
            <div class="flex items-center gap-2 print:hidden">
                <x-secondary-button type="button" onclick="window.print()">
                    <x-heroicon-o-printer class="w-4 h-4 mr-2" />
                    Imprimir
                </x-secondary-button>
                <x-primary-button x-data x-on:click="$dispatch('create-fixed-asset')">
                    <x-heroicon-o-plus class="w-4 h-4 mr-2" />
                    Nuevo activo
                </x-primary-button>
            </div>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:fixed-assets.fixed-asset-table />
        </div>
    </div>

    <livewire:fixed-assets.fixed-asset-form />
    <livewire:fixed-assets.dispose-form />

    <style>
        @media print {
            .print\:hidden { display: none !important; }
        }
    </style>
</x-app-layout>
