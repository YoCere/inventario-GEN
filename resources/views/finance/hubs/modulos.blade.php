<x-app-layout title="Módulos">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-foreground leading-tight">
            Módulos
        </h2>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            <p class="text-sm text-muted-foreground">Activos fijos, préstamos, presupuestos, producción y planilla.</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @can('assets.manage')
                    <a href="{{ route('finance.fixed-assets.index') }}" class="group flex items-start gap-3 rounded-lg border border-border bg-card p-4 transition-colors hover:bg-muted">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
                            <x-heroicon-o-building-office-2 class="h-5 w-5" />
                        </span>
                        <span class="flex flex-col">
                            <span class="font-medium text-foreground">Activos Fijos</span>
                            <span class="text-sm text-muted-foreground">Registro y depreciación de activos fijos.</span>
                        </span>
                    </a>

                    <a href="{{ route('finance.asset-categories.index') }}" class="group flex items-start gap-3 rounded-lg border border-border bg-card p-4 transition-colors hover:bg-muted">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
                            <x-heroicon-o-tag class="h-5 w-5" />
                        </span>
                        <span class="flex flex-col">
                            <span class="font-medium text-foreground">Categorías de activo</span>
                            <span class="text-sm text-muted-foreground">Clasificación y vida útil de activos.</span>
                        </span>
                    </a>
                @endcan

                @can('loans.manage')
                    <a href="{{ route('finance.loans.index') }}" class="group flex items-start gap-3 rounded-lg border border-border bg-card p-4 transition-colors hover:bg-muted">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
                            <x-heroicon-o-banknotes class="h-5 w-5" />
                        </span>
                        <span class="flex flex-col">
                            <span class="font-medium text-foreground">Préstamos</span>
                            <span class="text-sm text-muted-foreground">Préstamos y tabla de amortización.</span>
                        </span>
                    </a>
                @endcan

                @can('budgets.manage')
                    <a href="{{ route('finance.budgets.index') }}" class="group flex items-start gap-3 rounded-lg border border-border bg-card p-4 transition-colors hover:bg-muted">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
                            <x-heroicon-o-chart-pie class="h-5 w-5" />
                        </span>
                        <span class="flex flex-col">
                            <span class="font-medium text-foreground">Presupuestos</span>
                            <span class="text-sm text-muted-foreground">Presupuestos por período y seguimiento.</span>
                        </span>
                    </a>
                @endcan

                @can('production.manage')
                    <a href="{{ route('finance.production.index') }}" class="group flex items-start gap-3 rounded-lg border border-border bg-card p-4 transition-colors hover:bg-muted">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
                            <x-heroicon-o-cog-6-tooth class="h-5 w-5" />
                        </span>
                        <span class="flex flex-col">
                            <span class="font-medium text-foreground">Producción</span>
                            <span class="text-sm text-muted-foreground">Órdenes de producción.</span>
                        </span>
                    </a>

                    <a href="{{ route('finance.boms.index') }}" class="group flex items-start gap-3 rounded-lg border border-border bg-card p-4 transition-colors hover:bg-muted">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
                            <x-heroicon-o-beaker class="h-5 w-5" />
                        </span>
                        <span class="flex flex-col">
                            <span class="font-medium text-foreground">Recetas (BOM)</span>
                            <span class="text-sm text-muted-foreground">Listas de materiales para producción.</span>
                        </span>
                    </a>
                @endcan

                @if(auth()->user()?->isAdmin())
                    <a href="{{ route('users.payroll.index') }}" class="group flex items-start gap-3 rounded-lg border border-border bg-card p-4 transition-colors hover:bg-muted">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-foreground">
                            <x-heroicon-o-users class="h-5 w-5" />
                        </span>
                        <span class="flex flex-col">
                            <span class="font-medium text-foreground">Planilla</span>
                            <span class="text-sm text-muted-foreground">Nómina, descuentos y asiento automático.</span>
                        </span>
                    </a>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
