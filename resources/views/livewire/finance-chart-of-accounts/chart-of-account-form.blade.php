<div
    x-data="{ show: @entangle('showModal') }"
    x-show="show"
    x-cloak
    x-transition.opacity
    class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50 p-4"
    @keydown.escape.window="show = false"
>
    <div
        x-show="show"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="w-full max-w-2xl rounded-lg bg-white shadow-xl dark:bg-gray-800"
        @click.stop
    >
        <div class="p-6">
            <!-- Cabecera -->
            <div class="mb-6 space-y-1.5 border-b border-gray-200 pb-4 text-center sm:text-left">
                <h3 class="text-lg font-semibold leading-none tracking-tight text-foreground">
                    {{ $isEditing ? 'Editar Cuenta Contable' : 'Crear Cuenta Contable' }}
                </h3>
                <p class="text-sm text-muted-foreground">
                    {{ $isEditing
                        ? 'Realice cambios en la cuenta. Haga clic en guardar cuando haya terminado.'
                        : 'Agregue una nueva cuenta al plan de cuentas.' }}
                </p>
            </div>

            @if ($lockStructural)
                <div class="mb-4 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-600 dark:bg-amber-900/30 dark:text-amber-200">
                    <strong>Aviso:</strong> Esta cuenta tiene movimientos; solo se pueden editar nombre, descripción y estado.
                </div>
            @endif

            <form wire:submit="save" class="space-y-4">

                {{-- Código --}}
                <div class="space-y-2">
                    <x-input-label for="code" :value="__('Código')" />
                    <input
                        id="code"
                        type="text"
                        wire:model="code"
                        placeholder="Ej. 1, 1.1, 1.1.01"
                        @disabled($lockStructural)
                        @readonly($lockStructural)
                        class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm
                               {{ $lockStructural ? 'cursor-not-allowed opacity-60' : '' }}"
                    />
                    <x-input-error :messages="$errors->get('code')" />
                </div>

                {{-- Nombre --}}
                <div class="space-y-2">
                    <x-input-label for="name" :value="__('Nombre')" />
                    <input
                        id="name"
                        type="text"
                        wire:model="name"
                        placeholder="Ej. Caja y Bancos, Cuentas por Cobrar"
                        class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm"
                    />
                    <x-input-error :messages="$errors->get('name')" />
                </div>

                {{-- Cuenta padre --}}
                <div class="space-y-2">
                    <x-input-label for="parent_id" :value="__('Cuenta padre')" />
                    <select
                        id="parent_id"
                        wire:model="parent_id"
                        @disabled($lockStructural)
                        class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm
                               {{ $lockStructural ? 'cursor-not-allowed opacity-60' : '' }}"
                    >
                        <option value="">— Ninguna (cuenta raíz) —</option>
                        @foreach ($parentOptions as $opt)
                            <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('parent_id')" />
                </div>

                {{-- Tipo de cuenta --}}
                <div class="space-y-2">
                    <x-input-label for="account_type" :value="__('Tipo de cuenta')" />
                    <select
                        id="account_type"
                        wire:model.live="account_type"
                        @disabled($lockStructural)
                        class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm
                               {{ $lockStructural ? 'cursor-not-allowed opacity-60' : '' }}"
                    >
                        <option value="">— Seleccione —</option>
                        @foreach ($accountTypeOptions as $opt)
                            <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('account_type')" />
                </div>

                {{-- Saldo normal --}}
                <div class="space-y-2">
                    <x-input-label for="normal_balance" :value="__('Saldo normal')" />
                    <select
                        id="normal_balance"
                        wire:model="normal_balance"
                        @disabled($lockStructural)
                        class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm
                               {{ $lockStructural ? 'cursor-not-allowed opacity-60' : '' }}"
                    >
                        <option value="">— Seleccione —</option>
                        @foreach ($normalBalanceOptions as $opt)
                            <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('normal_balance')" />
                </div>

                {{-- Permite imputación (allows_posting) --}}
                <div class="flex items-center gap-3">
                    <input
                        id="allows_posting"
                        type="checkbox"
                        wire:model="allows_posting"
                        @disabled($lockStructural)
                        class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary
                               {{ $lockStructural ? 'cursor-not-allowed opacity-60' : '' }}"
                    />
                    <x-input-label for="allows_posting" :value="__('Permite imputación (cuenta de detalle)')" />
                    <x-input-error :messages="$errors->get('allows_posting')" />
                </div>

                {{-- Activa --}}
                <div class="flex items-center gap-3">
                    <input
                        id="is_active"
                        type="checkbox"
                        wire:model="is_active"
                        class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                    />
                    <x-input-label for="is_active" :value="__('Cuenta activa')" />
                    <x-input-error :messages="$errors->get('is_active')" />
                </div>

                {{-- Descripción --}}
                <div class="space-y-2">
                    <x-input-label for="description" :value="__('Descripción')" />
                    <textarea
                        id="description"
                        wire:model="description"
                        rows="3"
                        class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm"
                        placeholder="Descripción opcional..."
                    ></textarea>
                    <x-input-error :messages="$errors->get('description')" />
                </div>

                {{-- Acciones --}}
                <div class="mt-6 flex justify-end gap-3 border-t border-gray-200 pt-4">
                    <x-secondary-button type="button" wire:click="$set('showModal', false)">
                        {{ __('Cancelar') }}
                    </x-secondary-button>

                    <x-primary-button type="submit" wire:loading.attr="disabled">
                        <svg wire:loading wire:target="save" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <x-heroicon-o-check wire:loading.remove wire:target="save" class="w-4 h-4 mr-2" />
                        {{ $isEditing ? __('Guardar Cambios') : __('Crear Cuenta') }}
                    </x-primary-button>
                </div>
            </form>
        </div>
    </div>
</div>
