<x-app-layout title="Libro Diario">
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                {{ __('Libro Diario') }}
            </h2>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:finance-journal-entries.journal-entry-table />
        </div>
    </div>

    <livewire:finance-journal-entries.journal-entry-detail />
</x-app-layout>
