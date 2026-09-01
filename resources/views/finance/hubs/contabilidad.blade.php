<x-app-layout title="Contabilidad">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-foreground leading-tight">
            Contabilidad
        </h2>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            <p class="text-sm text-muted-foreground">Estructura contable, libro diario, períodos y reportes.</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @can('finance.accounting')
                    <a href="{{ route('finance.chart-of-accounts.index') }}" class="group flex items-start gap-3 rounded-lg border border-border bg-card p-4 transition-colors hover:bg-muted">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
                            <x-heroicon-o-clipboard-document-list class="h-5 w-5" />
                        </span>
                        <span class="flex flex-col">
                            <span class="font-medium text-foreground">Plan de cuentas</span>
                            <span class="text-sm text-muted-foreground">Catálogo contable y jerarquías.</span>
                        </span>
                    </a>

                    <a href="{{ route('finance.journal-entries.index') }}" class="group flex items-start gap-3 rounded-lg border border-border bg-card p-4 transition-colors hover:bg-muted">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
                            <x-heroicon-o-book-open class="h-5 w-5" />
                        </span>
                        <span class="flex flex-col">
                            <span class="font-medium text-foreground">Libro diario</span>
                            <span class="text-sm text-muted-foreground">Asientos y detalle Debe/Haber.</span>
                        </span>
                    </a>

                    @if(auth()->user()?->isAdmin())
                        <a href="{{ route('accounting.opening.index') }}" class="group flex items-start gap-3 rounded-lg border border-border bg-card p-4 transition-colors hover:bg-muted">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
                                <x-heroicon-o-lock-open class="h-5 w-5" />
                            </span>
                            <span class="flex flex-col">
                                <span class="font-medium text-foreground">Asiento de apertura</span>
                                <span class="text-sm text-muted-foreground">Registro inicial del ejercicio contable.</span>
                            </span>
                        </a>
                    @endif

                    <a href="{{ route('finance.accounting-periods.index') }}" class="group flex items-start gap-3 rounded-lg border border-border bg-card p-4 transition-colors hover:bg-muted">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
                            <x-heroicon-o-calendar-days class="h-5 w-5" />
                        </span>
                        <span class="flex flex-col">
                            <span class="font-medium text-foreground">Período contable</span>
                            <span class="text-sm text-muted-foreground">Gestionar y cerrar períodos, crear el siguiente año fiscal.</span>
                        </span>
                    </a>

                    <a href="{{ route('finance.worksheet') }}" class="group flex items-start gap-3 rounded-lg border border-border bg-card p-4 transition-colors hover:bg-muted">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
                            <x-heroicon-o-table-cells class="h-5 w-5" />
                        </span>
                        <span class="flex flex-col">
                            <span class="font-medium text-foreground">Hoja teórica</span>
                            <span class="text-sm text-muted-foreground">Hoja de trabajo previa a los estados financieros.</span>
                        </span>
                    </a>

                    <a href="{{ route('finance.statements.index') }}" class="group flex items-start gap-3 rounded-lg border border-border bg-card p-4 transition-colors hover:bg-muted">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
                            <x-heroicon-o-document-chart-bar class="h-5 w-5" />
                        </span>
                        <span class="flex flex-col">
                            <span class="font-medium text-foreground">Estados financieros</span>
                            <span class="text-sm text-muted-foreground">Los 5 estados financieros por período.</span>
                        </span>
                    </a>

                    <a href="{{ route('finance.trial-balance') }}" class="group flex items-start gap-3 rounded-lg border border-border bg-card p-4 transition-colors hover:bg-muted">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
                            <x-heroicon-o-scale class="h-5 w-5" />
                        </span>
                        <span class="flex flex-col">
                            <span class="font-medium text-foreground">Balance de Sumas y Saldos</span>
                            <span class="text-sm text-muted-foreground">Sumas y saldos de todas las cuentas.</span>
                        </span>
                    </a>
                @endcan

                @can('products.kardex')
                    <a href="{{ route('products.kardex.index') }}" class="group flex items-start gap-3 rounded-lg border border-border bg-card p-4 transition-colors hover:bg-muted">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
                            <x-heroicon-o-archive-box class="h-5 w-5" />
                        </span>
                        <span class="flex flex-col">
                            <span class="font-medium text-foreground">Kardex valorizado</span>
                            <span class="text-sm text-muted-foreground">Entradas, salidas y saldo valorizado por producto.</span>
                        </span>
                    </a>
                @endcan
            </div>
        </div>
    </div>
</x-app-layout>
