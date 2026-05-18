<x-app-layout title="Stock por ubicación">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-foreground leading-tight">
            {{ __('Stock por ubicación') }}
        </h2>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:reports.stock-by-location />
        </div>
    </div>
</x-app-layout>
