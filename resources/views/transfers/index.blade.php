<x-app-layout title="Transferencias de stock">
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                {{ __('Transferencias de stock') }}
            </h2>
            @if(auth()->user()->isAdmin())
                <x-primary-button x-data x-on:click="$dispatch('create-transfer')">
                    <x-heroicon-o-plus class="w-4 h-4 mr-2" />
                    {{ __('Nueva transferencia') }}
                </x-primary-button>
            @endif
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:transfers.transfer-table />
        </div>
    </div>

    <livewire:transfers.transfer-form />
</x-app-layout>
