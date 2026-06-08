<x-app-layout title="Recetas (BOM)">
    <x-slot name="header">
        <div class="flex justify-between items-center gap-2">
            <h2 class="font-semibold text-xl text-foreground leading-tight">Recetas (BOM)</h2>
            <x-primary-button x-data x-on:click="$dispatch('create-bom')">Nueva receta</x-primary-button>
        </div>
    </x-slot>
    <div class="py-4">
        <livewire:boms.bom-table />
        <livewire:boms.bom-form />
    </div>
</x-app-layout>
