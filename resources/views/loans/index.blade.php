<x-app-layout title="Préstamos">
    <x-slot name="header">
        <div class="flex justify-between items-center gap-2">
            <h2 class="font-semibold text-xl text-foreground leading-tight">Préstamos</h2>
            <x-primary-button x-data x-on:click="$dispatch('create-loan')">Nuevo préstamo</x-primary-button>
        </div>
    </x-slot>
    <div class="py-4">
        <livewire:loans.loan-table />
        <livewire:loans.loan-form />
    </div>
</x-app-layout>
