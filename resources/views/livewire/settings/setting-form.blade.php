<x-modal name="setting-form-modal" :title="''" maxWidth="2xl">
    <div class="p-6">
        <!-- Custom Header -->
        <div class="mb-6 space-y-1.5 text-center sm:text-left border-b border-gray-200 pb-4">
            <h3 class="text-lg font-semibold leading-none tracking-tight text-foreground">
                {{ __('Edit Setting') }}
            </h3>
            <p class="text-sm text-muted-foreground">
                {{ __('Update the value of this setting.') }}
            </p>
        </div>

        <form wire:submit="save" class="space-y-4">
            <!-- Label (Readonly) -->
            <div class="space-y-2">
                <x-input-label for="key" :value="__('Setting Name')" />
                <div class="px-3 py-2 text-sm font-medium border rounded-md border-input bg-muted/50 text-foreground">
                    {{ $label }}
                </div>
            </div>

            <!-- Value -->
            <div class="space-y-2">
                <x-input-label for="value" :value="__('Value')" />
                
                @if($key === 'currency_position')
                    <select id="value" wire:model="value" class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm">
                        <option value="left">Izquierda (Ejemplo: Rp 10.000)</option>
                        <option value="right">Derecha (Ejemplo: 10.000 Rp)</option>
                    </select>
                @elseif($key === 'dashboard_display_mode')
                    <select id="value" wire:model="value" class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm">
                        <option value="percent">Porcentajes (simple)</option>
                        <option value="amount">Montos (tecnico)</option>
                    </select>
                @elseif($key === 'opening_balance_date')
                    <input
                        type="date"
                        id="value"
                        wire:model="value"
                        class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm"
                    >
                @elseif(in_array($key, [
                    'opening_balance_amount',
                    'payroll_antiquity_base_amount',
                    'payroll_rc_iva_minimum',
                    'payroll_rc_iva_compensable',
                    'payroll_solidarity_1_threshold',
                    'payroll_solidarity_2_threshold',
                ]))
                    <input
                        type="number"
                        id="value"
                        wire:model="value"
                        min="0"
                        step="0.01"
                        class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm"
                        placeholder="Ingresa un monto..."
                    >
                @elseif($key === 'currency_fraction_digits')
                    <input 
                        type="number" 
                        id="value" 
                        wire:model="value" 
                        min="0"
                        max="4"
                        class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm" 
                        placeholder="0 para IDR, 2 para USD"
                    >
                @elseif(in_array($key, [
                    'discount_rate_annual',
                    'tax_iva_rate',
                    'tax_it_rate',
                    'payroll_border_bonus_rate',
                    'payroll_labor_contribution_rate',
                    'payroll_rc_iva_rate',
                    'payroll_solidarity_1_rate',
                    'payroll_solidarity_2_rate',
                    'payroll_employer_contribution_rate',
                    'payroll_aguinaldo_provision_rate',
                    'payroll_indemnization_provision_rate',
                ]))
                    <input
                        type="number"
                        id="value"
                        wire:model="value"
                        min="0"
                        max="200"
                        step="0.01"
                        class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm"
                        placeholder="Ejemplo: 13"
                    >
                @elseif(in_array($key, [
                    'tax_include_iva', 'tax_include_it',
                    'telegram_enabled',
                    'telegram_notify_low_stock', 'telegram_notify_daily',
                    'ai_chatbot_enabled', 'ai_search_enabled',
                    'ai_voice_enabled', 'ai_voice_reply', 'ai_vision_enabled',
                    'shop_show_out_of_stock',
                    'auto_create_next_period',
                ]))
                    <select id="value" wire:model="value" class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm">
                        <option value="1">✅ Activo</option>
                        <option value="0">❌ Inactivo</option>
                    </select>
                @elseif($key === 'telegram_bot_paused')
                    <select id="value" wire:model="value" class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm">
                        <option value="0">✅ Activo (el bot responde normalmente)</option>
                        <option value="1">⏸️ Pausado (el bot no responde)</option>
                    </select>
                @elseif(in_array($key, [
                    'telegram_bot_token', 'telegram_webhook_secret',
                    'anthropic_api_key', 'openai_api_key',
                ]))
                    <div x-data="{ show: false }" class="relative">
                        <input
                            :type="show ? 'text' : 'password'"
                            id="value"
                            wire:model="value"
                            autocomplete="new-password"
                            class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm pr-10"
                            placeholder="Ingresa la clave o token..."
                        >
                        <button
                            type="button"
                            @click="show = !show"
                            class="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground transition-colors"
                            :title="show ? 'Ocultar' : 'Mostrar'"
                        >
                            <template x-if="!show">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                            </template>
                            <template x-if="show">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 4.411m0 0L21 21" />
                                </svg>
                            </template>
                        </button>
                    </div>
                    <p class="text-xs text-amber-600 dark:text-amber-400 flex items-center gap-1 mt-1">
                        🔒 Campo sensible — no compartas este valor con nadie.
                    </p>
                @elseif(in_array($key, ['currency_thousand_separator', 'currency_decimal_separator']))
                    <select id="value" wire:model="value" class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm">
                        <option value=".">Punto (.)</option>
                        <option value=",">Coma (,)</option>
                        <option value=" ">Espacio ( )</option>
                        <option value="">Ninguno</option>
                    </select>
                @elseif(in_array($key, ['store_address']))
                    <textarea
                        id="value"
                        wire:model="value"
                        rows="4"
                        class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm"
                        placeholder="Ingresa un valor..."
                    ></textarea>
                @else
                    <input 
                        type="text" 
                        id="value" 
                        wire:model="value" 
                        class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm" 
                        placeholder="Ingresa un valor..."
                    >
                @endif
                <x-input-error :messages="$errors->get('value')" />
            </div>

            @if(in_array($key, ['opening_balance_date', 'opening_balance_amount']))
                <div class="space-y-2 rounded-md border border-amber-200 bg-amber-50 p-3">
                    <x-input-label for="admin_password" :value="'Confirmar contrasena de administrador'" />
                    <input
                        id="admin_password"
                        type="password"
                        wire:model="admin_password"
                        class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm"
                        placeholder="Ingresa tu contrasena"
                    >
                    <p class="text-xs text-amber-700">Este ajuste es sensible y requiere validacion de contrasena.</p>
                    <x-input-error :messages="$errors->get('admin_password')" />
                </div>
            @endif

            <!-- Actions -->
            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end border-t border-gray-200 pt-4">
                <x-secondary-button type="button" class="w-full sm:w-auto justify-center" x-on:click="$dispatch('close-modal', { name: 'setting-form-modal' })">
                    {{ __('Cancel') }}
                </x-secondary-button>

                <x-primary-button type="submit" class="w-full sm:w-auto justify-center" wire:loading.attr="disabled">
                    <svg wire:loading wire:target="save" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <x-heroicon-o-check wire:loading.remove wire:target="save" class="w-4 h-4 mr-2" />
                    {{ __('Save Changes') }}
                </x-primary-button>
            </div>
        </form>
    </div>
</x-modal>
