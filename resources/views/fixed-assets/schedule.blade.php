<x-app-layout title="Cédula de Depreciación">
    <x-slot name="header">
        <div class="flex justify-between items-center gap-2">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                {{ __('Cédula de Depreciación') }}
            </h2>
            <div class="flex items-center gap-2 print:hidden">
                <x-secondary-button type="button" onclick="window.print()">
                    <x-heroicon-o-printer class="w-4 h-4 mr-2" />
                    Imprimir
                </x-secondary-button>
                <a href="{{ route('finance.fixed-assets.index') }}">
                    <x-secondary-button type="button">
                        <x-heroicon-o-arrow-left class="w-4 h-4 mr-2" />
                        Volver
                    </x-secondary-button>
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-card rounded-lg border border-border shadow-sm p-6">
                <livewire:fixed-assets.depreciation-schedule :asset-id="$assetId" />
            </div>
        </div>
    </div>

    <style>
        @media print {
            .print\:hidden { display: none !important; }
        }
    </style>
</x-app-layout>
