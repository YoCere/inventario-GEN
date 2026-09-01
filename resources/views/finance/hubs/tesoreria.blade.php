<x-app-layout title="Tesorería">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-foreground leading-tight">
            Tesorería
        </h2>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            <p class="text-sm text-muted-foreground">Operaciones financieras manuales y clasificación.</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @can('finance.view')
                    <a href="{{ route('finance.transactions.index') }}" class="group flex items-start gap-3 rounded-lg border border-border bg-card p-4 transition-colors hover:bg-muted">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
                            <x-heroicon-o-arrows-right-left class="h-5 w-5" />
                        </span>
                        <span class="flex flex-col">
                            <span class="font-medium text-foreground">Transacciones</span>
                            <span class="text-sm text-muted-foreground">Ingresos y egresos con trazabilidad.</span>
                        </span>
                    </a>

                    <a href="{{ route('finance.categories.index') }}" class="group flex items-start gap-3 rounded-lg border border-border bg-card p-4 transition-colors hover:bg-muted">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
                            <x-heroicon-o-tag class="h-5 w-5" />
                        </span>
                        <span class="flex flex-col">
                            <span class="font-medium text-foreground">Categorías</span>
                            <span class="text-sm text-muted-foreground">Clasificación de movimientos financieros.</span>
                        </span>
                    </a>
                @endcan
            </div>
        </div>
    </div>
</x-app-layout>
