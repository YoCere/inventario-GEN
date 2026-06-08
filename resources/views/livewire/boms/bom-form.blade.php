<x-modal name="bom-form-modal" :title="''" maxWidth="2xl">
    <div class="p-6">
        <!-- Cabecera Personalizada -->
        <div class="mb-6 space-y-1.5 text-center sm:text-left border-b border-gray-200 pb-4">
            <h3 class="text-lg font-semibold leading-none tracking-tight text-foreground">
                {{ $this->bomId ? 'Editar Receta (BOM)' : 'Nueva Receta (BOM)' }}
            </h3>
            <p class="text-sm text-muted-foreground">
                Define el producto terminado, tasas de costos y componentes (materias primas).
            </p>
        </div>

        <form wire:submit="save" class="space-y-4">
            <!-- Producto terminado -->
            <div class="space-y-2">
                <x-input-label :value="__('Producto terminado')" />
                <select wire:model="productId"
                    class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm">
                    <option value="">-- Seleccionar producto --</option>
                    @foreach($productOptions as $opt)
                        <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('productId')" />
            </div>

            <!-- Tasas de costo -->
            <div class="grid grid-cols-3 gap-4">
                <div class="space-y-2">
                    <x-input-label for="mod_rate" :value="__('Tasa MOD (Bs/ud)')" />
                    <x-text-input id="mod_rate" type="number" step="0.01" min="0" wire:model="mod_rate" class="block w-full" />
                    <x-input-error :messages="$errors->get('mod_rate')" />
                </div>
                <div class="space-y-2">
                    <x-input-label for="moi_rate" :value="__('Tasa MOI (Bs/ud)')" />
                    <x-text-input id="moi_rate" type="number" step="0.01" min="0" wire:model="moi_rate" class="block w-full" />
                    <x-input-error :messages="$errors->get('moi_rate')" />
                </div>
                <div class="space-y-2">
                    <x-input-label for="cif_rate" :value="__('Tasa CIF (Bs/ud)')" />
                    <x-text-input id="cif_rate" type="number" step="0.01" min="0" wire:model="cif_rate" class="block w-full" />
                    <x-input-error :messages="$errors->get('cif_rate')" />
                </div>
            </div>

            <!-- Componentes -->
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <x-input-label :value="__('Componentes (materias primas)')" />
                    <button type="button" wire:click="addComponent"
                        class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Agregar componente
                    </button>
                </div>
                <x-input-error :messages="$errors->get('components')" />

                @foreach($this->components as $i => $comp)
                <div class="flex gap-3 items-end p-3 rounded-md border border-border bg-muted/30">
                    <div class="flex-1 space-y-1">
                        <x-input-label :value="__('Materia prima')" class="text-xs" />
                        <select wire:model="components.{{ $i }}.component_product_id"
                            class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring text-sm">
                            <option value="">-- Seleccionar --</option>
                            @foreach($productOptions as $opt)
                                <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('components.' . $i . '.component_product_id')" />
                    </div>
                    <div class="w-32 space-y-1">
                        <x-input-label :value="__('Cant./ud')" class="text-xs" />
                        <x-text-input type="number" step="0.0001" min="0.0001"
                            wire:model="components.{{ $i }}.quantity_per_unit"
                            class="block w-full text-sm" />
                        <x-input-error :messages="$errors->get('components.' . $i . '.quantity_per_unit')" />
                    </div>
                    @if(count($this->components) > 1)
                    <button type="button" wire:click="removeComponent({{ $i }})"
                        class="mb-0.5 p-1.5 rounded text-red-500 hover:bg-red-50 hover:text-red-700 transition-colors"
                        title="Eliminar componente">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                    @endif
                </div>
                @endforeach
            </div>

            <!-- Acciones -->
            <div class="mt-6 flex justify-end gap-3 border-t border-gray-200 pt-4">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', { name: 'bom-form-modal' })">
                    {{ __('Cancelar') }}
                </x-secondary-button>

                <x-primary-button type="submit" wire:loading.attr="disabled">
                    <svg wire:loading wire:target="save" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <x-heroicon-o-check wire:loading.remove wire:target="save" class="w-4 h-4 mr-2" />
                    {{ __('Guardar Receta') }}
                </x-primary-button>
            </div>
        </form>
    </div>
</x-modal>
