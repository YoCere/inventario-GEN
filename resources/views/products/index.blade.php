<x-app-layout title="Productos">
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                {{ __('Productos') }}
            </h2>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('products.report') }}">
                    <x-secondary-button type="button">
                        <x-heroicon-o-chart-bar class="w-4 h-4 mr-2" />
                        Generar informe
                    </x-secondary-button>
                </a>
                @can('products.manage')
                    <x-secondary-button x-data x-on:click="$dispatch('import-receipt')">
                        <x-heroicon-o-camera class="w-4 h-4 mr-2" />
                        Importar de recibo
                    </x-secondary-button>
                    <x-primary-button x-data x-on:click="$dispatch('create-product')">
                        <x-heroicon-o-plus class="w-4 h-4 mr-2" />
                        Crear producto
                    </x-primary-button>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:products.product-table />
        </div>
    </div>

    <livewire:products.product-form />
    <livewire:products.product-detail />
    <livewire:products.receipt-import />
    {{-- Atajo desde Inicio: ?nuevo=1 abre el formulario de alta --}}
    @if(request()->boolean('nuevo') && auth()->user()->can('products.manage'))
        <script>
            document.addEventListener('livewire:initialized', () => Livewire.dispatch('create-product'));
        </script>
    @endif
</x-app-layout>
