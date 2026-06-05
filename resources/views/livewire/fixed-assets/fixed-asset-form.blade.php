<x-modal name="fixed-asset-form-modal" :title="''" maxWidth="2xl">
    <div class="p-6">
        <!-- Cabecera Personalizada -->
        <div class="mb-6 space-y-1.5 text-center sm:text-left border-b border-gray-200 pb-4">
            <h3 class="text-lg font-semibold leading-none tracking-tight text-foreground">
                Registrar Activo Fijo
            </h3>
            <p class="text-sm text-muted-foreground">
                Complete los datos del nuevo activo fijo.
            </p>
        </div>

        <form wire:submit="save" class="space-y-4">
            <!-- Modo -->
            <div class="space-y-2">
                <x-input-label :value="__('Tipo de registro')" />
                <div class="grid grid-cols-2 gap-4">
                    <label class="cursor-pointer">
                        <input type="radio" name="mode" value="new" wire:model="mode" class="peer sr-only">
                        <div class="relative flex items-center justify-center gap-2 rounded-lg border border-input bg-background px-4 py-2.5 text-center transition-all hover:bg-accent hover:text-accent-foreground peer-checked:border-primary peer-checked:bg-primary/10 peer-checked:text-primary peer-checked:ring-1 peer-checked:ring-primary">
                            <span class="text-sm font-medium">Alta nueva</span>
                        </div>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="mode" value="opening" wire:model="mode" class="peer sr-only">
                        <div class="relative flex items-center justify-center gap-2 rounded-lg border border-input bg-background px-4 py-2.5 text-center transition-all hover:bg-accent hover:text-accent-foreground peer-checked:border-amber-500 peer-checked:bg-amber-50 peer-checked:text-amber-700 peer-checked:ring-1 peer-checked:ring-amber-500">
                            <span class="text-sm font-medium">Apertura (ya existente)</span>
                        </div>
                    </label>
                </div>
                <x-input-error :messages="$errors->get('mode')" />
            </div>

            <!-- Categoría -->
            <div class="space-y-2">
                <x-input-label for="asset_category_id" :value="__('Categoría')" />
                <select id="asset_category_id" wire:model.live="asset_category_id"
                    class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm">
                    <option value="">-- Seleccionar categoría --</option>
                    @foreach($categoryOptions as $opt)
                        <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('asset_category_id')" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <!-- Código -->
                <x-form-input name="code" label="Código" type="text" wire:model="code" placeholder="Ej. VEH-001" required />
                <!-- Nombre -->
                <x-form-input name="name" label="Nombre" type="text" wire:model="name" placeholder="Ej. Camioneta Toyota" required />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <!-- Fecha de adquisición -->
                <div class="space-y-2">
                    <x-input-label for="acquisition_date" :value="__('Fecha de adquisición')" />
                    <x-text-input id="acquisition_date" type="date" wire:model="acquisition_date" class="block w-full" />
                    <x-input-error :messages="$errors->get('acquisition_date')" />
                </div>
                <!-- Fecha inicio depreciación -->
                <div class="space-y-2">
                    <x-input-label for="depreciation_start_date" :value="__('Inicio depreciación')" />
                    <x-text-input id="depreciation_start_date" type="date" wire:model="depreciation_start_date" class="block w-full" />
                    <x-input-error :messages="$errors->get('depreciation_start_date')" />
                </div>
            </div>

            <div class="grid grid-cols-3 gap-4">
                <!-- Costo de adquisición -->
                <div class="space-y-2">
                    <x-input-label for="acquisition_cost" :value="__('Costo adq. (Bs)')" />
                    <x-text-input id="acquisition_cost" type="number" step="0.01" min="0" wire:model="acquisition_cost" class="block w-full" />
                    <x-input-error :messages="$errors->get('acquisition_cost')" />
                </div>
                <!-- Valor residual -->
                <div class="space-y-2">
                    <x-input-label for="residual_value" :value="__('Valor residual (Bs)')" />
                    <x-text-input id="residual_value" type="number" step="0.01" min="0" wire:model="residual_value" class="block w-full" />
                    <x-input-error :messages="$errors->get('residual_value')" />
                </div>
                <!-- Vida útil -->
                <div class="space-y-2">
                    <x-input-label for="useful_life_months" :value="__('Vida útil (meses)')" />
                    <x-text-input id="useful_life_months" type="number" min="1" wire:model="useful_life_months" class="block w-full" />
                    <x-input-error :messages="$errors->get('useful_life_months')" />
                </div>
            </div>

            <!-- Cuenta financiamiento (solo modo new) -->
            @if($mode === 'new')
            <div class="space-y-2">
                <x-input-label for="funding_account_code" :value="__('Cuenta de financiamiento')" />
                <select id="funding_account_code" wire:model="funding_account_code"
                    class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm">
                    <option value="">-- Seleccionar cuenta --</option>
                    @foreach($accountOptions as $opt)
                        <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('funding_account_code')" />
            </div>
            @endif

            <!-- Depreciación acumulada a la fecha (solo modo opening) -->
            @if($mode === 'opening')
            <div class="space-y-2">
                <x-input-label for="accumulated_to_date" :value="__('Depreciación acumulada a la fecha (Bs)')" />
                <x-text-input id="accumulated_to_date" type="number" step="0.01" min="0" wire:model="accumulated_to_date" class="block w-full" />
                <x-input-error :messages="$errors->get('accumulated_to_date')" />
            </div>
            @endif

            <!-- Acciones -->
            <div class="mt-6 flex justify-end gap-3 border-t border-gray-200 pt-4">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', { name: 'fixed-asset-form-modal' })">
                    {{ __('Cancelar') }}
                </x-secondary-button>

                <x-primary-button type="submit" wire:loading.attr="disabled">
                    <svg wire:loading wire:target="save" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <x-heroicon-o-check wire:loading.remove wire:target="save" class="w-4 h-4 mr-2" />
                    {{ __('Registrar Activo') }}
                </x-primary-button>
            </div>
        </form>
    </div>
</x-modal>
