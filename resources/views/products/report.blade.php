<x-app-layout title="Informe de Productos">
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('products.index') }}"
                   class="inline-flex items-center justify-center rounded-md h-8 w-8 border border-input bg-background hover:bg-accent transition-colors text-muted-foreground hover:text-foreground">
                    <x-heroicon-o-arrow-left class="h-4 w-4" />
                </a>
                <div>
                    <h2 class="font-semibold text-xl text-foreground leading-tight">
                        Informe de Productos
                    </h2>
                    <p class="text-sm text-muted-foreground mt-0.5">
                        Bajo stock · Más vendidos · Sin movimiento · Recomendados para compra
                    </p>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:products.product-report />
        </div>
    </div>
</x-app-layout>
