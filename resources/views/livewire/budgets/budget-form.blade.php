<x-modal name="budget-form-modal" :title="''" maxWidth="2xl">
    <div class="p-6">
        <div class="mb-6 space-y-1.5 text-center sm:text-left border-b border-gray-200 pb-4">
            <h3 class="text-lg font-semibold leading-none tracking-tight text-foreground">
                Nuevo Presupuesto
            </h3>
            <p class="text-sm text-muted-foreground">
                Complete los parámetros del presupuesto. Las líneas se sembrarán automáticamente desde los movimientos reales del período base.
            </p>
        </div>

        <form wire:submit="save" class="space-y-4">
            <!-- Nombre -->
            <div class="space-y-2">
                <x-input-label for="budget_name" :value="__('Nombre del presupuesto')" />
                <x-text-input id="budget_name" type="text" wire:model="name" class="block w-full" placeholder="Ej. Plan 2026" />
                <x-input-error :messages="$errors->get('name')" />
            </div>

            <!-- Período base -->
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2">
                    <x-input-label for="base_from" :value="__('Período base desde')" />
                    <x-text-input id="base_from" type="date" wire:model="base_from" class="block w-full" />
                    <x-input-error :messages="$errors->get('base_from')" />
                </div>
                <div class="space-y-2">
                    <x-input-label for="base_to" :value="__('Período base hasta')" />
                    <x-text-input id="base_to" type="date" wire:model="base_to" class="block w-full" />
                    <x-input-error :messages="$errors->get('base_to')" />
                </div>
            </div>

            <!-- Parámetros de proyección -->
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2">
                    <x-input-label for="years" :value="__('Años de proyección')" />
                    <x-text-input id="years" type="number" min="1" max="20" wire:model="years" class="block w-full" />
                    <x-input-error :messages="$errors->get('years')" />
                </div>
                <div class="space-y-2">
                    <x-input-label for="growth_pct" :value="__('Crecimiento global (%)')" />
                    <x-text-input id="growth_pct" type="number" step="0.01" min="0" wire:model="growth_pct" class="block w-full" />
                    <x-input-error :messages="$errors->get('growth_pct')" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2">
                    <x-input-label for="discount_rate_pct" :value="__('Tasa de descuento (%)')" />
                    <x-text-input id="discount_rate_pct" type="number" step="0.01" min="0" wire:model="discount_rate_pct" class="block w-full" />
                    <x-input-error :messages="$errors->get('discount_rate_pct')" />
                </div>
                <div class="space-y-2">
                    <x-input-label for="iue_rate_pct" :value="__('Tasa IUE (%)')" />
                    <x-text-input id="iue_rate_pct" type="number" step="0.01" min="0" wire:model="iue_rate_pct" class="block w-full" />
                    <x-input-error :messages="$errors->get('iue_rate_pct')" />
                </div>
            </div>

            <!-- Acciones -->
            <div class="mt-6 flex justify-end gap-3 border-t border-gray-200 pt-4">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', { name: 'budget-form-modal' })">
                    {{ __('Cancelar') }}
                </x-secondary-button>

                <x-primary-button type="submit" wire:loading.attr="disabled">
                    <svg wire:loading wire:target="save" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <x-heroicon-o-check wire:loading.remove wire:target="save" class="w-4 h-4 mr-2" />
                    {{ __('Crear presupuesto') }}
                </x-primary-button>
            </div>
        </form>
    </div>
</x-modal>
