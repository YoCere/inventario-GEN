<x-modal name="warehouse-form-modal" :title="''" maxWidth="2xl">
    <div class="p-6">
        <div class="mb-6 space-y-1.5 text-center sm:text-left border-b border-gray-200 pb-4">
            <h3 class="text-lg font-semibold leading-none tracking-tight text-foreground">
                {{ $isEditing ? 'Editar almacén' : 'Crear almacén' }}
            </h3>
            <p class="text-sm text-muted-foreground">
                {{ $isEditing ? 'Modifica datos del almacén.' : 'Agrega un nuevo almacén al sistema.' }}
            </p>
        </div>

        <form wire:submit="save" class="space-y-4">
            <x-form-input
                name="name"
                label="Nombre"
                type="text"
                wire:model="name"
                placeholder="ej. Almacén Central"
                required
            />

            <x-form-input
                name="address"
                label="Dirección (opcional)"
                type="text"
                wire:model="address"
                placeholder="ej. Av. Principal 123, La Paz"
            />

            <div class="flex flex-col gap-3 pt-2">
                <label class="inline-flex items-center cursor-pointer">
                    <input type="checkbox" wire:model="is_active"
                        class="w-5 h-5 rounded border-2 border-primary text-primary focus:ring-primary/20">
                    <span class="ml-3 text-sm font-medium text-gray-700">Activo</span>
                </label>

                <label class="inline-flex items-center cursor-pointer">
                    <input type="checkbox" wire:model="is_default"
                        class="w-5 h-5 rounded border-2 border-blue-500 text-blue-500 focus:ring-blue-500/20">
                    <span class="ml-3 text-sm font-medium text-gray-700">Predeterminado (default)</span>
                </label>
                <p class="text-xs text-gray-500">Solo puede haber un almacén predeterminado a la vez.</p>
            </div>

            <div class="mt-6 flex justify-end gap-3 border-t border-gray-200 pt-4">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', { name: 'warehouse-form-modal' })">
                    {{ __('Cancelar') }}
                </x-secondary-button>

                <x-primary-button type="submit" wire:loading.attr="disabled">
                    <svg wire:loading wire:target="save" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <x-heroicon-o-check wire:loading.remove wire:target="save" class="w-4 h-4 mr-2" />
                    {{ $isEditing ? __('Guardar cambios') : __('Crear almacén') }}
                </x-primary-button>
            </div>
        </form>
    </div>
</x-modal>
