<div class="mb-6">
    <div class="bg-card border border-border rounded-lg p-6">
        <h3 class="text-base font-semibold text-foreground mb-4">Registrar Producción</h3>

        @if(session('success'))
            <div class="mb-4 rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">
                {{ session('error') }}
            </div>
        @endif

        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Receta (BOM) -->
                <div class="space-y-2">
                    <x-input-label :value="__('Receta (BOM)')" />
                    <select wire:model.live="bomId"
                        class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm">
                        <option value="">-- Seleccionar receta --</option>
                        @foreach($bomOptions as $opt)
                            <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('bomId')" />
                </div>

                <!-- Cantidad -->
                <div class="space-y-2">
                    <x-input-label for="quantity" :value="__('Cantidad')" />
                    <x-text-input id="quantity" type="number" min="1" wire:model.live="quantity" class="block w-full" />
                    <x-input-error :messages="$errors->get('quantity')" />
                </div>

                <!-- Fecha de producción -->
                <div class="space-y-2">
                    <x-input-label for="production_date" :value="__('Fecha de producción')" />
                    <x-text-input id="production_date" type="date" wire:model="production_date" class="block w-full" />
                    <x-input-error :messages="$errors->get('production_date')" />
                </div>

                <!-- Ubicación -->
                <div class="space-y-2">
                    <x-input-label :value="__('Ubicación')" />
                    <select wire:model="location_id"
                        class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm">
                        <option value="">-- Seleccionar ubicación --</option>
                        @foreach($locationOptions as $opt)
                            <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('location_id')" />
                </div>
            </div>

            <!-- Estimación de costo -->
            @if($estimate)
            <div class="mt-2 rounded-md bg-blue-50 border border-blue-200 p-4">
                <p class="text-sm font-semibold text-blue-800 mb-2">Estimación de costos</p>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-3 text-sm">
                    <div>
                        <span class="text-xs text-muted-foreground uppercase tracking-wide block">Materiales</span>
                        <span class="font-medium text-foreground">{{ format_money($estimate['material_cost']) }}</span>
                    </div>
                    <div>
                        <span class="text-xs text-muted-foreground uppercase tracking-wide block">MOD</span>
                        <span class="font-medium text-foreground">{{ format_money($estimate['mod_cost']) }}</span>
                    </div>
                    <div>
                        <span class="text-xs text-muted-foreground uppercase tracking-wide block">MOI</span>
                        <span class="font-medium text-foreground">{{ format_money($estimate['moi_cost']) }}</span>
                    </div>
                    <div>
                        <span class="text-xs text-muted-foreground uppercase tracking-wide block">CIF</span>
                        <span class="font-medium text-foreground">{{ format_money($estimate['cif_cost']) }}</span>
                    </div>
                    <div>
                        <span class="text-xs text-muted-foreground uppercase tracking-wide block">Total</span>
                        <span class="font-semibold text-foreground">{{ format_money($estimate['total_cost']) }}</span>
                    </div>
                    <div>
                        <span class="text-xs text-muted-foreground uppercase tracking-wide block">Costo/ud</span>
                        <span class="font-semibold text-foreground">{{ format_money($estimate['unit_cost']) }}</span>
                    </div>
                </div>
            </div>
            @endif

            <div class="flex justify-end">
                <x-primary-button type="submit" wire:loading.attr="disabled">
                    <svg wire:loading wire:target="save" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <x-heroicon-o-check wire:loading.remove wire:target="save" class="w-4 h-4 mr-2" />
                    {{ __('Producir') }}
                </x-primary-button>
            </div>
        </form>
    </div>
</div>
