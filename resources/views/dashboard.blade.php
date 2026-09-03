<x-app-layout title="Dashboard">
    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if(auth()->user()?->hasRole('emprendedor'))
                @php
                    $tieneProductos = \App\Models\Product::query()->exists();
                    $tieneVentas = \App\Models\Sale::query()->exists();
                @endphp
                @unless($tieneProductos && $tieneVentas)
                    <div class="mb-6 rounded-lg border border-border bg-card p-5">
                        <h3 class="text-lg font-semibold text-foreground">¡Bienvenido! Arrancá en 3 pasos</h3>
                        <ul class="mt-3 space-y-2 text-sm">
                            <li class="flex items-center gap-2">
                                <span>{{ $tieneProductos ? '✅' : '⬜' }}</span>
                                <a href="{{ route('products.index') }}" class="text-primary hover:underline">Cargá tus productos</a>
                            </li>
                            <li class="flex items-center gap-2">
                                <span>{{ $tieneVentas ? '✅' : '⬜' }}</span>
                                <a href="{{ route('sales.create') }}" class="text-primary hover:underline">Registrá tu primera venta</a>
                            </li>
                            <li class="flex items-center gap-2">
                                <span>▶️</span>
                                <a href="{{ route('sales.index') }}" class="text-primary hover:underline">Mirá tus ventas</a>
                            </li>
                        </ul>
                    </div>
                @endunless
            @endif
            <livewire:dashboard.dashboard />
        </div>
    </div>
</x-app-layout>
