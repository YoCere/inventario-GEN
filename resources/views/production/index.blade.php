<x-app-layout title="Producción">
    <x-slot name="header">
        <div class="flex justify-between items-center gap-2">
            <h2 class="font-semibold text-xl text-foreground leading-tight">Producción</h2>
        </div>
    </x-slot>
    <div class="py-4">
        @if(session('error'))
            <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">
                {{ session('error') }}
            </div>
        @endif
        <livewire:production.produce-form />
        <livewire:production.production-order-table />
    </div>
</x-app-layout>
