<x-app-layout title="Transacciones Financieras">
    <x-slot name="header">
        <div class="flex justify-between items-center gap-2">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                {{ __('Transacciones Financieras') }}
            </h2>
            <div class="flex items-center gap-2 print:hidden">
                <x-secondary-button type="button" onclick="window.print()">
                    <x-heroicon-o-printer class="w-4 h-4 mr-2" />
                    Imprimir
                </x-secondary-button>
                <x-primary-button x-data x-on:click="$dispatch('create-finance-transaction')">
                    <x-heroicon-o-plus class="w-4 h-4 mr-2" />
                    Crear Transacción
                </x-primary-button>
            </div>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:finance-transactions.finance-transaction-table />
        </div>
    </div>

    <livewire:finance-transactions.finance-transaction-form />
    <livewire:finance-transactions.finance-transaction-detail />

    <style>
        @media print {
            .print\:hidden { display: none !important; }
        }
    </style>
</x-app-layout>
