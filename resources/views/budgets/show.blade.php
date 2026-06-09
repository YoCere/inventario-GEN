<x-app-layout title="Proyección de Presupuesto">
    <x-slot name="header">
        <div class="flex justify-between items-center gap-2">
            <h2 class="font-semibold text-xl text-foreground leading-tight">Proyección de Presupuesto</h2>
        </div>
    </x-slot>
    <div class="py-4">
        <livewire:budgets.budget-detail :budget="(int) $budget" />
    </div>
</x-app-layout>
