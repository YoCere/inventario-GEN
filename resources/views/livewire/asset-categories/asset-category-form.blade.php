<x-modal name="asset-category-form-modal" :title="''" maxWidth="2xl">
    <div class="p-6">
        <!-- Cabecera Personalizada -->
        <div class="mb-6 space-y-1.5 text-center sm:text-left border-b border-gray-200 pb-4">
            <h3 class="text-lg font-semibold leading-none tracking-tight text-foreground">
                {{ $isEditing ? 'Editar Categoría de Activo' : 'Crear Categoría de Activo' }}
            </h3>
            <p class="text-sm text-muted-foreground">
                {{ $isEditing ? 'Realice cambios en la categoría. Haga clic en guardar cuando haya terminado.' : 'Agregue una nueva categoría de activo fijo.' }}
            </p>
        </div>

        <form wire:submit="save" class="space-y-4">
            <!-- Nombre -->
            <x-form-input
                name="name"
                label="Nombre"
                type="text"
                wire:model="name"
                placeholder="Ej. Vehículos, Maquinaria"
                required
            />

            <div class="grid grid-cols-2 gap-4">
                <!-- Vida útil -->
                <div class="space-y-2">
                    <x-input-label for="useful_life_months" :value="__('Vida útil (meses)')" />
                    <x-text-input id="useful_life_months" type="number" wire:model="useful_life_months" min="1" class="block w-full" />
                    <x-input-error :messages="$errors->get('useful_life_months')" />
                </div>

                <!-- Tasa anual -->
                <div class="space-y-2">
                    <x-input-label for="annual_rate_pct" :value="__('Tasa anual (%)')" />
                    <x-text-input id="annual_rate_pct" type="number" step="0.01" wire:model="annual_rate_pct" min="0" class="block w-full" />
                    <x-input-error :messages="$errors->get('annual_rate_pct')" />
                </div>
            </div>

            <!-- Es diferido -->
            <div class="flex items-center gap-3">
                <input id="is_deferred" type="checkbox" wire:model="is_deferred"
                    class="rounded border-input text-primary focus:ring-ring" />
                <x-input-label for="is_deferred" :value="__('Es activo diferido')" />
                <x-input-error :messages="$errors->get('is_deferred')" />
            </div>

            <!-- Cuenta PPE -->
            <div class="space-y-2">
                <x-input-label for="ppe_account_code" :value="__('Cuenta PPE (Propiedad, Planta y Equipo)')" />
                <select id="ppe_account_code" wire:model="ppe_account_code"
                    class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm">
                    <option value="">-- Seleccionar cuenta --</option>
                    @foreach($accountOptions as $opt)
                        <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('ppe_account_code')" />
            </div>

            <!-- Cuenta Depreciación Acumulada -->
            <div class="space-y-2">
                <x-input-label for="accumulated_account_code" :value="__('Cuenta Depreciación Acumulada')" />
                <select id="accumulated_account_code" wire:model="accumulated_account_code"
                    class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm">
                    <option value="">-- Seleccionar cuenta --</option>
                    @foreach($accountOptions as $opt)
                        <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('accumulated_account_code')" />
            </div>

            <!-- Cuenta Gasto Depreciación -->
            <div class="space-y-2">
                <x-input-label for="expense_account_code" :value="__('Cuenta Gasto Depreciación')" />
                <select id="expense_account_code" wire:model="expense_account_code"
                    class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm">
                    <option value="">-- Seleccionar cuenta --</option>
                    @foreach($accountOptions as $opt)
                        <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('expense_account_code')" />
            </div>

            <!-- Acciones -->
            <div class="mt-6 flex justify-end gap-3 border-t border-gray-200 pt-4">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', { name: 'asset-category-form-modal' })">
                    {{ __('Cancelar') }}
                </x-secondary-button>

                <x-primary-button type="submit" wire:loading.attr="disabled">
                    <svg wire:loading wire:target="save" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <x-heroicon-o-check wire:loading.remove wire:target="save" class="w-4 h-4 mr-2" />
                    {{ $isEditing ? __('Guardar Cambios') : __('Crear Categoría') }}
                </x-primary-button>
            </div>
        </form>
    </div>
</x-modal>
