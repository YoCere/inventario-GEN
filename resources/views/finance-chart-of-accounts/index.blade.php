<x-app-layout title="Plan de Cuentas">
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                {{ __('Plan de Cuentas') }}
            </h2>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:finance-chart-of-accounts.chart-of-account-table />
        </div>
    </div>

    <livewire:finance-chart-of-accounts.chart-of-account-detail />
</x-app-layout>
