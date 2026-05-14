<x-modal name="location-form-modal" :title="''" maxWidth="2xl">
    <div class="p-6">
        <div class="mb-6 space-y-1.5 text-center sm:text-left border-b border-gray-200 pb-4">
            <h3 class="text-lg font-semibold leading-none tracking-tight text-foreground">
                {{ $isEditing ? 'Editar ubicación' : 'Crear ubicación' }}
            </h3>
            <p class="text-sm text-muted-foreground">
                Sección, pasillo, estante o bin dentro de un almacén.
            </p>
        </div>

        <form wire:submit="save" class="space-y-4">
            <!-- Almacén -->
            <div class="space-y-2">
                <x-input-label for="warehouse_id" value="Almacén" required />
                <select id="warehouse_id" wire:model.live="warehouse_id"
                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                    <option value="">Seleccionar almacén...</option>
                    @foreach($warehouses as $wh)
                        <option value="{{ $wh->id }}">{{ $wh->name }}{{ $wh->is_default ? ' (default)' : '' }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('warehouse_id')" />
            </div>

            <!-- Ubicación padre -->
            <div class="space-y-2">
                <x-input-label for="parent_location_id" value="Ubicación padre (opcional)" />
                <select id="parent_location_id" wire:model="parent_location_id"
                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    @if(!$warehouse_id) disabled @endif>
                    <option value="">— Ninguna (nivel raíz) —</option>
                    @foreach($parentOptions as $opt)
                        <option value="{{ $opt->id }}">{{ $opt->name }} ({{ $opt->type }})</option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-500">Para anidar (ej. Pasillo A › Estante 1 › Bin 3).</p>
            </div>

            <!-- Nombre -->
            <x-form-input
                name="name"
                label="Nombre"
                type="text"
                wire:model="name"
                placeholder="ej. Estante metales norte"
                required
            />

            <!-- Tipo -->
            <div class="space-y-2">
                <x-input-label for="type" value="Tipo" required />
                <select id="type" wire:model="type"
                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                    <option value="section">Sección (pasillo, área)</option>
                    <option value="position">Posición (estante, columna)</option>
                    <option value="bin">Bin (caja, gaveta)</option>
                </select>
            </div>

            <div class="flex flex-col gap-3 pt-2">
                <label class="inline-flex items-center cursor-pointer">
                    <input type="checkbox" wire:model="is_active"
                        class="w-5 h-5 rounded border-2 border-primary text-primary focus:ring-primary/20">
                    <span class="ml-3 text-sm font-medium text-gray-700">Activo</span>
                </label>

                <label class="inline-flex items-center cursor-pointer">
                    <input type="checkbox" wire:model="is_default"
                        class="w-5 h-5 rounded border-2 border-blue-500 text-blue-500 focus:ring-blue-500/20">
                    <span class="ml-3 text-sm font-medium text-gray-700">Ubicación default del almacén</span>
                </label>
                <p class="text-xs text-gray-500">Productos sin ubicación específica caerán aquí.</p>
            </div>

            <div class="mt-6 flex justify-end gap-3 border-t border-gray-200 pt-4">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', { name: 'location-form-modal' })">
                    {{ __('Cancelar') }}
                </x-secondary-button>

                <x-primary-button type="submit" wire:loading.attr="disabled">
                    <svg wire:loading wire:target="save" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <x-heroicon-o-check wire:loading.remove wire:target="save" class="w-4 h-4 mr-2" />
                    {{ $isEditing ? __('Guardar cambios') : __('Crear ubicación') }}
                </x-primary-button>
            </div>
        </form>
    </div>
</x-modal>
