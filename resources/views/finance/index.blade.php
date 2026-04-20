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
                    <a href="{{ route('finance.chart-of-accounts.index') }}" class="rounded-md border border-border px-4 py-3 hover:bg-muted/40">
                        <p class="font-medium">Plan de cuentas</p>
                        <p class="text-xs text-muted-foreground mt-1">Catalogo contable y jerarquias</p>
                    </a>
                    <a href="{{ route('finance.journal-entries.index') }}" class="rounded-md border border-border px-4 py-3 hover:bg-muted/40">
                        <p class="font-medium">Libro diario</p>
                        <p class="text-xs text-muted-foreground mt-1">Asientos y detalle Debe/Haber</p>
                    </a>
                    <a href="{{ route('finance.kardex.index') }}" class="rounded-md border border-border px-4 py-3 hover:bg-muted/40">
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
