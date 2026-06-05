<x-modal name="dispose-form-modal" :title="''" maxWidth="lg">
    <div class="p-6">
        <!-- Cabecera -->
        <div class="mb-6 space-y-1.5 text-center sm:text-left border-b border-gray-200 pb-4">
            <h3 class="text-lg font-semibold leading-none tracking-tight text-foreground">
                Dar de Baja Activo Fijo
            </h3>
            @if($asset)
            <p class="text-sm text-muted-foreground">
                Activo: <span class="font-medium">{{ $asset->code }} — {{ $asset->name }}</span>
            </p>
            @endif
        </div>

        <div class="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-md text-sm text-amber-800">
            <strong>Ayuda:</strong> Cuenta de resultado: use <strong>4.2</strong> (Otros Ingresos) si hay ganancia, o
            <strong>6.6</strong> (Pérdida en Venta de Activos) si hay pérdida. El sistema genera el asiento automáticamente por el signo.
        </div>

        <form wire:submit="save" class="space-y-4">
            <!-- Fecha de baja -->
            <div class="space-y-2">
                <x-input-label for="disposal_date" :value="__('Fecha de baja')" />
                <x-text-input id="disposal_date" type="date" wire:model="disposal_date" class="block w-full" />
                <x-input-error :messages="$errors->get('disposal_date')" />
            </div>

            <!-- Monto de venta -->
            <div class="space-y-2">
                <x-input-label for="sale_amount" :value="__('Monto de venta (Bs, 0 si es sin valor)')" />
                <x-text-input id="sale_amount" type="number" step="0.01" min="0" wire:model="sale_amount" class="block w-full" />
                <x-input-error :messages="$errors->get('sale_amount')" />
            </div>

            <!-- Cuenta de efectivo (opcional) -->
            <div class="space-y-2">
                <x-input-label for="cash_account_code" :value="__('Cuenta de efectivo (opcional si monto > 0)')" />
                <select id="cash_account_code" wire:model="cash_account_code"
                    class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm">
                    <option value="">-- Ninguna --</option>
                    @foreach($accountOptions as $opt)
                        <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('cash_account_code')" />
            </div>

            <!-- Cuenta de resultado -->
            <div class="space-y-2">
                <x-input-label for="result_account_code" :value="__('Cuenta de resultado (ganancia/pérdida)')" />
                <select id="result_account_code" wire:model="result_account_code"
                    class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm">
                    <option value="">-- Seleccionar cuenta --</option>
                    @foreach($accountOptions as $opt)
                        <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('result_account_code')" />
            </div>

            <!-- Acciones -->
            <div class="mt-6 flex justify-end gap-3 border-t border-gray-200 pt-4">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', { name: 'dispose-form-modal' })">
                    {{ __('Cancelar') }}
                </x-secondary-button>

                <x-primary-button type="submit" wire:loading.attr="disabled" class="bg-red-600 hover:bg-red-700">
                    <svg wire:loading wire:target="save" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    {{ __('Confirmar Baja') }}
                </x-primary-button>
            </div>
        </form>
    </div>
</x-modal>
