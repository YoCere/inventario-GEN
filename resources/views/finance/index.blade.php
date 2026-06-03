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
                <p class="text-sm text-muted-foreground mb-4">Estructura contable, libro diario, kardex, planilla de sueldos y estados financieros.</p>
                <div class="grid grid-cols-1 sm:grid-cols-5 gap-3">
                    @if(auth()->user()->isAdmin())
                        <a href="{{ route('finance.accounting-periods.index') }}" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 hover:bg-amber-100 sm:col-span-5">
                            <div class="flex items-center gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-amber-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                                </svg>
                                <p class="font-medium text-amber-800">Periodos Contables</p>
                            </div>
                            <p class="text-xs text-amber-700 mt-1">Gestionar y cerrar periodos · Crear el siguiente año fiscal</p>
                        </a>
                    @endif
                    <a href="{{ route('finance.chart-of-accounts.index') }}" class="rounded-md border border-border px-4 py-3 hover:bg-muted/40">
                        <p class="font-medium">Plan de cuentas</p>
                        <p class="text-xs text-muted-foreground mt-1">Catalogo contable y jerarquias</p>
                    </a>
                    <a href="{{ route('finance.journal-entries.index') }}" class="rounded-md border border-border px-4 py-3 hover:bg-muted/40">
                        <p class="font-medium">Libro diario</p>
                        <p class="text-xs text-muted-foreground mt-1">Asientos y detalle Debe/Haber</p>
                    </a>
                    <a href="{{ route('products.kardex.index') }}" class="rounded-md border border-border px-4 py-3 hover:bg-muted/40">
                        <p class="font-medium">Kardex valorizado</p>
                        <p class="text-xs text-muted-foreground mt-1">Entradas, salidas y saldo por producto</p>
                    </a>
                    @if(auth()->user()->isAdmin())
                        <a href="{{ route('finance.payroll.index') }}" class="rounded-md border border-border px-4 py-3 hover:bg-muted/40">
                            <p class="font-medium">Planilla de sueldos</p>
                            <p class="text-xs text-muted-foreground mt-1">Nomina, descuentos y asiento automatico</p>
                        </a>
                    @endif
                    <a href="{{ route('finance.statements.index') }}" class="rounded-md border border-border px-4 py-3 hover:bg-muted/40">
                        <p class="font-medium">Estados financieros</p>
                        <p class="text-xs text-muted-foreground mt-1">Los 5 estados por periodo</p>
                    </a>
                </div>
            </div>

            <div class="bg-card border border-border rounded-lg p-5">
                <h3 class="text-lg font-semibold mb-2">Tesoreria y control</h3>
                <p class="text-sm text-muted-foreground mb-4">Operaciones financieras manuales y clasificacion.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <a href="{{ route('finance.transactions.index') }}" class="rounded-md border border-border px-4 py-3 hover:bg-muted/40">
                        <p class="font-medium">Transacciones financieras</p>
                        <p class="text-xs text-muted-foreground mt-1">Ingresos/egresos y trazabilidad</p>
                    </a>
                    <a href="{{ route('finance.categories.index') }}" class="rounded-md border border-border px-4 py-3 hover:bg-muted/40">
                        <p class="font-medium">Categorias financieras</p>
                        <p class="text-xs text-muted-foreground mt-1">Clasificacion de movimientos</p>
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
