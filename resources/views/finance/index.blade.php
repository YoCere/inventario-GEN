<x-app-layout title="Finanzas">
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                Finanzas
            </h2>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-card border border-border rounded-lg p-5">
                <h3 class="text-lg font-semibold mb-2">Contabilidad</h3>
                <p class="text-sm text-muted-foreground mb-4">Estructura contable, libro diario y estados financieros.</p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <a href="{{ route('finance.chart-of-accounts.index') }}" class="rounded-md border border-border px-4 py-3 hover:bg-muted/40">
                        <p class="font-medium">Plan de cuentas</p>
                        <p class="text-xs text-muted-foreground mt-1">Catálogo contable y jerarquías</p>
                    </a>
                    <a href="{{ route('finance.journal-entries.index') }}" class="rounded-md border border-border px-4 py-3 hover:bg-muted/40">
                        <p class="font-medium">Libro diario</p>
                        <p class="text-xs text-muted-foreground mt-1">Asientos y detalle Debe/Haber</p>
                    </a>
                    <a href="{{ route('finance.statements.index') }}" class="rounded-md border border-border px-4 py-3 hover:bg-muted/40">
                        <p class="font-medium">Estados financieros</p>
                        <p class="text-xs text-muted-foreground mt-1">Los 5 estados por periodo</p>
                    </a>
                </div>
            </div>

            <div class="bg-card border border-border rounded-lg p-5">
                <h3 class="text-lg font-semibold mb-2">Tesorería y Control</h3>
                <p class="text-sm text-muted-foreground mb-4">Operaciones financieras manuales y clasificación.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <a href="{{ route('finance.transactions.index') }}" class="rounded-md border border-border px-4 py-3 hover:bg-muted/40">
                        <p class="font-medium">Transacciones financieras</p>
                        <p class="text-xs text-muted-foreground mt-1">Ingresos/egresos y trazabilidad</p>
                    </a>
                    <a href="{{ route('finance.categories.index') }}" class="rounded-md border border-border px-4 py-3 hover:bg-muted/40">
                        <p class="font-medium">Categorías financieras</p>
                        <p class="text-xs text-muted-foreground mt-1">Clasificación de movimientos</p>
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
