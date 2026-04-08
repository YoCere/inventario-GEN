<x-modal name="supplier-detail-modal" focusable>
    @if($supplier)
        <div class="p-6">
            <!-- Cabecera -->
            <div class="mb-6 space-y-1.5 text-center sm:text-left border-b border-gray-200 pb-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold leading-none tracking-tight text-foreground">
                        {{ __('Detalles del Proveedor') }}
                    </h3>
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ __('Información detallada de') }} {{ $supplier->name }}.
                </p>
            </div>

                <div class="space-y-6">
                    <div class="space-y-1">
                        <label class="text-sm font-medium leading-none text-muted-foreground">{{ __('Nombre') }}</label>
                        <p class="text-sm text-foreground font-medium">{{ $supplier->name }}</p>
                    </div>

                    <div class="space-y-1">
                        <label class="text-sm font-medium leading-none text-muted-foreground">{{ __('Persona de Contacto') }}</label>
                        <p class="text-sm text-foreground font-medium">{{ $supplier->contact_person }}</p>
                    </div>

                    <!-- Información de Contacto -->
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div class="space-y-1">
                            <label class="text-sm font-medium leading-none text-muted-foreground">{{ __('Correo Electrónico') }}</label>
                            <p class="text-sm text-foreground font-medium">{{ $supplier->email ?? '-' }}</p>
                        </div>

                        <div class="space-y-1">
                            <label class="text-sm font-medium leading-none text-muted-foreground">{{ __('Teléfono') }}</label>
                            <p class="text-sm text-foreground font-medium">{{ $supplier->phone ?? '-' }}</p>
                        </div>
                    </div>

                    <div class="space-y-1">
                        <label class="text-sm font-medium leading-none text-muted-foreground">{{ __('Dirección') }}</label>
                        <p class="text-sm text-foreground font-medium">{{ $supplier->address ?? '-' }}</p>
                    </div>

                    <div class="space-y-1">
                        <label class="text-sm font-medium leading-none text-muted-foreground">{{ __('Notas') }}</label>
                        <p class="text-sm text-foreground font-medium whitespace-pre-line">{{ $supplier->notes ?? '-' }}</p>
                    </div>

                    <!-- Metadatos -->
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div class="space-y-1">
                            <label class="text-sm font-medium leading-none text-muted-foreground">{{ __('Creado el') }}</label>
                            <p class="text-sm text-foreground font-medium">{{ $supplier->created_at?->format('d M Y, H:i') ?? '-' }}</p>
                        </div>

                        <div class="space-y-1">
                            <label class="text-sm font-medium leading-none text-muted-foreground">{{ __('Última Actualización') }}</label>
                            <p class="text-sm text-foreground font-medium">{{ $supplier->updated_at?->format('d M Y, H:i') ?? '-' }}</p>
                        </div>
                    </div>
                </div>

            <!-- Acciones -->
            <div class="mt-6 flex items-center justify-end gap-x-2 pt-4 border-t border-border">
                <x-secondary-button x-on:click="$dispatch('close-modal', { name: 'supplier-detail-modal' })">
                    {{ __('Cerrar') }}
                </x-secondary-button>

                <x-primary-button type="button" x-on:click="$dispatch('close-modal', { name: 'supplier-detail-modal' }); $dispatch('edit-supplier', { supplier: {{ $supplier->id }} })" class="bg-amber-500 hover:bg-amber-600 focus:ring-amber-500">
                    <x-heroicon-o-pencil-square class="w-4 h-4 mr-2" />
                    {{ __('Editar Proveedor') }}
                </x-primary-button>
            </div>
        </div>
    @else
        <div class="p-8 text-center flex flex-col items-center justify-center space-y-3">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
            <span class="text-sm text-muted-foreground">{{ __('Cargando detalles...') }}</span>
        </div>
    @endif
</x-modal>