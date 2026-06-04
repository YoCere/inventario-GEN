<x-app-layout title="Nuevo Asiento Manual">
    <x-slot name="header">
        <div class="flex justify-between items-center gap-2">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                Nuevo Asiento Manual
            </h2>
        </div>
    </x-slot>

    <livewire:finance-journal-entries.manual-journal-entry-form />
</x-app-layout>
