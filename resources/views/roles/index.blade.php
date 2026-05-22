<x-app-layout title="Roles y permisos">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-foreground leading-tight">
            Roles y permisos
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:roles.roles-index />
        </div>
    </div>
</x-app-layout>
