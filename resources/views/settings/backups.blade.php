<x-app-layout title="Backups">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-foreground leading-tight">
            Backups y Respaldos
        </h2>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <livewire:settings.backup-manager />
        </div>
    </div>
</x-app-layout>
