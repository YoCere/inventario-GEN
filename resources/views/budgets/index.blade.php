<x-app-layout title="Presupuestos">
    <x-slot name="header">
        <div class="flex justify-between items-center gap-2">
            <h2 class="font-semibold text-xl text-foreground leading-tight">Presupuestos</h2>
            @if(auth()->user()->isAdmin())
                <x-primary-button x-data x-on:click="$dispatch('create-budget')">Nuevo presupuesto</x-primary-button>
            @endif
        </div>
    </x-slot>
    <div class="py-4">
        @if(session('success'))
            <div class="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-800 border border-green-200">
                {{ session('success') }}
            </div>
        @endif
        <livewire:budgets.budget-table />
        <livewire:budgets.budget-form />
    </div>
</x-app-layout>
