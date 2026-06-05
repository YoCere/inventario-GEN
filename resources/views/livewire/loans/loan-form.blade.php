<x-modal name="loan-form-modal" :title="''" maxWidth="2xl">
    <div class="p-6">
        <!-- Cabecera Personalizada -->
        <div class="mb-6 space-y-1.5 text-center sm:text-left border-b border-gray-200 pb-4">
            <h3 class="text-lg font-semibold leading-none tracking-tight text-foreground">
                Registrar Préstamo
            </h3>
            <p class="text-sm text-muted-foreground">
                Complete los datos del nuevo préstamo.
            </p>
        </div>

        <form wire:submit="save" class="space-y-4">
            <!-- Modo -->
            <div class="space-y-2">
                <x-input-label :value="__('Tipo de registro')" />
                <div class="grid grid-cols-2 gap-4">
                    <label class="cursor-pointer">
                        <input type="radio" name="mode" value="new" wire:model.live="mode" class="peer sr-only">
                        <div class="relative flex items-center justify-center gap-2 rounded-lg border border-input bg-background px-4 py-2.5 text-center transition-all hover:bg-accent hover:text-accent-foreground peer-checked:border-primary peer-checked:bg-primary/10 peer-checked:text-primary peer-checked:ring-1 peer-checked:ring-primary">
                            <span class="text-sm font-medium">Préstamo nuevo</span>
                        </div>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="mode" value="opening" wire:model.live="mode" class="peer sr-only">
                        <div class="relative flex items-center justify-center gap-2 rounded-lg border border-input bg-background px-4 py-2.5 text-center transition-all hover:bg-accent hover:text-accent-foreground peer-checked:border-amber-500 peer-checked:bg-amber-50 peer-checked:text-amber-700 peer-checked:ring-1 peer-checked:ring-amber-500">
                            <span class="text-sm font-medium">Apertura (ya existente)</span>
                        </div>
                    </label>
                </div>
                <x-input-error :messages="$errors->get('mode')" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <!-- Acreedor -->
                <div class="space-y-2">
                    <x-input-label for="lender" :value="__('Acreedor')" />
                    <x-text-input id="lender" type="text" wire:model="lender" class="block w-full" placeholder="Ej. Banco Nacional" />
                    <x-input-error :messages="$errors->get('lender')" />
                </div>
                <!-- Código -->
                <div class="space-y-2">
                    <x-input-label for="code" :value="__('Código')" />
                    <x-text-input id="code" type="text" wire:model="code" class="block w-full" placeholder="Ej. L-001" />
                    <x-input-error :messages="$errors->get('code')" />
                </div>
            </div>

            <div class="grid grid-cols-3 gap-4">
                <!-- Capital -->
                <div class="space-y-2">
                    <x-input-label for="principal" :value="__('Capital (Bs)')" />
                    <x-text-input id="principal" type="number" step="0.01" min="0.01" wire:model="principal" class="block w-full" />
                    <x-input-error :messages="$errors->get('principal')" />
                </div>
                <!-- Tasa anual -->
                <div class="space-y-2">
                    <x-input-label for="annual_rate_pct" :value="__('Tasa anual (%)')" />
                    <x-text-input id="annual_rate_pct" type="number" step="0.01" min="0" wire:model="annual_rate_pct" class="block w-full" />
                    <x-input-error :messages="$errors->get('annual_rate_pct')" />
                </div>
                <!-- Plazo -->
                <div class="space-y-2">
                    <x-input-label for="term_months" :value="__('Plazo (meses)')" />
                    <x-text-input id="term_months" type="number" min="1" wire:model="term_months" class="block w-full" />
                    <x-input-error :messages="$errors->get('term_months')" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <!-- Fecha de inicio -->
                <div class="space-y-2">
                    <x-input-label for="start_date" :value="__('Fecha de inicio')" />
                    <x-text-input id="start_date" type="date" wire:model="start_date" class="block w-full" />
                    <x-input-error :messages="$errors->get('start_date')" />
                </div>
                <!-- Día de pago -->
                <div class="space-y-2">
                    <x-input-label for="payment_day" :value="__('Día de pago (1-28)')" />
                    <x-text-input id="payment_day" type="number" min="1" max="28" wire:model="payment_day" class="block w-full" />
                    <x-input-error :messages="$errors->get('payment_day')" />
                </div>
            </div>

            <!-- Cuentas contables -->
            <div class="space-y-2">
                <x-input-label :value="__('Cuenta pasivo (préstamo)')" />
                <select wire:model="liability_account_code"
                    class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm">
                    <option value="">-- Seleccionar cuenta --</option>
                    @foreach($accountOptions as $opt)
                        <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('liability_account_code')" />
            </div>

            <div class="space-y-2">
                <x-input-label :value="__('Cuenta de intereses')" />
                <select wire:model="interest_account_code"
                    class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm">
                    <option value="">-- Seleccionar cuenta --</option>
                    @foreach($accountOptions as $opt)
                        <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('interest_account_code')" />
            </div>

            <div class="space-y-2">
                <x-input-label :value="__('Cuenta de pago (banco/caja)')" />
                <select wire:model="payment_account_code"
                    class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm">
                    <option value="">-- Seleccionar cuenta --</option>
                    @foreach($accountOptions as $opt)
                        <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('payment_account_code')" />
            </div>

            <!-- Fecha de apertura (solo modo opening) -->
            @if($mode === 'opening')
            <div class="space-y-2">
                <x-input-label for="as_of_date" :value="__('Fecha de apertura (saldo a la fecha)')" />
                <x-text-input id="as_of_date" type="date" wire:model="as_of_date" class="block w-full" />
                <x-input-error :messages="$errors->get('as_of_date')" />
            </div>
            @endif

            <!-- Acciones -->
            <div class="mt-6 flex justify-end gap-3 border-t border-gray-200 pt-4">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', { name: 'loan-form-modal' })">
                    {{ __('Cancelar') }}
                </x-secondary-button>

                <x-primary-button type="submit" wire:loading.attr="disabled">
                    <svg wire:loading wire:target="save" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <x-heroicon-o-check wire:loading.remove wire:target="save" class="w-4 h-4 mr-2" />
                    {{ __('Registrar Préstamo') }}
                </x-primary-button>
            </div>
        </form>
    </div>
</x-modal>
