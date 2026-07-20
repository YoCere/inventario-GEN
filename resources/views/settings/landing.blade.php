<x-app-layout title="Landing de la tienda">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-foreground leading-tight">
            {{ __('Landing de la tienda') }}
        </h2>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            <livewire:settings.landing-share-settings />
            <livewire:settings.landing-editor />
        </div>
    </div>
</x-app-layout>
