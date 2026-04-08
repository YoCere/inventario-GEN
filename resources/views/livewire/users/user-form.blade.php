<x-modal name="user-form-modal" :title="''" maxWidth="2xl">
    <div class="p-6">
        <!-- Cabecera Personalizada -->
        <div class="mb-6 space-y-1.5 text-center sm:text-left border-b border-gray-200 pb-4">
            <h3 class="text-lg font-semibold leading-none tracking-tight text-foreground">
                {{ $isEditing ? 'Editar Usuario' : 'Crear Usuario' }}
            </h3>
            <p class="text-sm text-muted-foreground">
                {{ $isEditing ? 'Actualizar información del usuario.' : 'Agregar un nuevo usuario al sistema.' }}
            </p>
        </div>

        <form wire:submit="save" class="space-y-4">
            <!-- Nombre -->
            <x-form-input
                name="name"
                label="Nombre"
                type="text"
                wire:model="name"
                required
                placeholder="Nombre Completo"
            />

            <!-- Nombre de Usuario -->
            <x-form-input
                name="username"
                label="Nombre de Usuario"
                type="text"
                wire:model="username"
                required
                placeholder="Nombre de usuario único"
            />

            <!-- Correo Electrónico -->
            <x-form-input
                name="email"
                label="Correo Electrónico"
                type="email"
                wire:model="email"
                required
                placeholder="correo@ejemplo.com"
            />

            <!-- Contraseña -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="space-y-2">
                    <x-input-label for="password" :value="__('Contraseña')" />
                    <x-text-input
                        id="password"
                        name="password"
                        type="password"
                        wire:model="password"
                        :required="!$isEditing"
                        autocomplete="new-password"
                        placeholder="{{ $isEditing ? 'Dejar en blanco para mantener la actual' : 'Mínimo 8 caracteres' }}"
                    />
                    <x-input-error :messages="$errors->get('password')" />
                </div>

                <div class="space-y-2">
                    <x-input-label for="password_confirmation" :value="__('Confirmar Contraseña')" />
                    <x-text-input
                        id="password_confirmation"
                        name="password_confirmation"
                        type="password"
                        wire:model="password_confirmation"
                        :required="!$isEditing"
                        autocomplete="new-password"
                    />
                </div>
            </div>

            <!-- Acciones -->
            <div class="mt-6 flex justify-end gap-3 border-t border-gray-200 pt-4">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', { name: 'user-form-modal' })">
                    {{ __('Cancelar') }}
                </x-secondary-button>

                <x-primary-button type="submit" wire:loading.attr="disabled">
                    <svg wire:loading wire:target="save" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <x-heroicon-o-check wire:loading.remove wire:target="save" class="w-4 h-4 mr-2" />
                    {{ $isEditing ? __('Guardar Cambios') : __('Crear Usuario') }}
                </x-primary-button>
            </div>
        </form>
    </div>
</x-modal>