<x-modal name="accounting-period-form-modal" :title="''" maxWidth="lg">
    <div class="p-6">
        <div class="mb-6 space-y-1.5 text-center sm:text-left border-b border-gray-200 pb-4">
            <h3 class="text-lg font-semibold leading-none tracking-tight text-foreground">
                Crear Periodo Contable
            </h3>
            <p class="text-sm text-muted-foreground">
                Define el nombre y el rango de fechas del nuevo periodo. Una vez cerrado, no podrá registrarse asientos en él.
            </p>
        </div>

        <form wire:submit="save" class="space-y-4">
            <!-- Nombre -->
            <x-form-input
                name="name"
                label="Nombre del periodo"
                type="text"
                wire:model="name"
                placeholder="Ej. 2027"
                required
            />

            <!-- Fecha inicio -->
            <div class="space-y-1.5">
                <x-input-label for="start_date" :value="__('Fecha de inicio')" />
                <input
                    id="start_date"
                    type="date"
                    wire:model="start_date"
                    class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm"
                    required
                />
                <x-input-error :messages="$errors->get('start_date')" />
            </div>

            <!-- Fecha fin -->
            <div class="space-y-1.5">
                <x-input-label for="end_date" :value="__('Fecha de fin')" />
                <input
                    id="end_date"
                    type="date"
                    wire:model="end_date"
                    class="block w-full rounded-md border-input bg-background shadow-sm focus:border-ring focus:ring-ring sm:text-sm"
                    required
                />
                <x-input-error :messages="$errors->get('end_date')" />
            </div>

            <!-- Alerta informativa -->
            <div class="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                </svg>
                <span>El periodo se creará en estado <strong>Abierto</strong>. Crea el siguiente periodo antes de cerrar el actual para evitar interrupciones en el registro de operaciones.</span>
            </div>

            <!-- Acciones -->
            <div class="mt-6 flex justify-end gap-3 border-t border-gray-200 pt-4">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', { name: 'accounting-period-form-modal' })">
                    Cancelar
                </x-secondary-button>

                <x-primary-button type="submit" wire:loading.attr="disabled">
                    <svg wire:loading wire:target="save" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <x-heroicon-o-check wire:loading.remove wire:target="save" class="w-4 h-4 mr-2" />
                    Crear Periodo
                </x-primary-button>
            </div>
        </form>
    </div>
</x-modal>
