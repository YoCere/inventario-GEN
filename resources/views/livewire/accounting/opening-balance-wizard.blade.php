<div class="py-4">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-card border border-border rounded-lg shadow-sm">
            <div class="p-6 border-b border-border">
                <h3 class="text-lg font-semibold text-foreground">Asiento de Apertura</h3>
                <p class="text-sm text-muted-foreground mt-1">Ingrese los saldos iniciales en bolivianos. El sistema propone valores para los rubros que ya tienen datos (inventario, activos fijos, préstamos).</p>
            </div>

            <form wire:submit="save" class="p-6 space-y-6">

                {{-- Fecha --}}
                <div class="space-y-2 max-w-xs">
                    <x-input-label for="date" value="Fecha de apertura" :required="true" />
                    <input
                        id="date"
                        type="date"
                        wire:model="date"
                        class="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                    />
                    <x-input-error :messages="$errors->get('date')" />
                </div>

                {{-- Rubros --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <x-input-label for="cash" value="Caja" />
                        <input id="cash" type="number" step="0.01" wire:model.live="cash"
                            class="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring" />
                        <x-input-error :messages="$errors->get('cash')" />
                    </div>

                    <div class="space-y-2">
                        <x-input-label for="bank" value="Banco" />
                        <input id="bank" type="number" step="0.01" wire:model.live="bank"
                            class="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring" />
                        <x-input-error :messages="$errors->get('bank')" />
                    </div>

                    <div class="space-y-2">
                        <x-input-label for="receivable" value="Cuentas por cobrar" />
                        <input id="receivable" type="number" step="0.01" wire:model.live="receivable"
                            class="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring" />
                        <x-input-error :messages="$errors->get('receivable')" />
                    </div>

                    <div class="space-y-2">
                        <x-input-label for="inventory" value="Inventario" />
                        <input id="inventory" type="number" step="0.01" wire:model.live="inventory"
                            class="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring" />
                        <x-input-error :messages="$errors->get('inventory')" />
                    </div>

                    <div class="space-y-2">
                        <x-input-label for="ppe" value="Bienes de uso" />
                        <input id="ppe" type="number" step="0.01" wire:model.live="ppe"
                            class="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring" />
                        <x-input-error :messages="$errors->get('ppe')" />
                    </div>

                    <div class="space-y-2">
                        <x-input-label for="accumulated_depreciation" value="Depreciación acumulada" />
                        <input id="accumulated_depreciation" type="number" step="0.01" wire:model.live="accumulated_depreciation"
                            class="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring" />
                        <x-input-error :messages="$errors->get('accumulated_depreciation')" />
                    </div>

                    <div class="space-y-2">
                        <x-input-label for="payable" value="Cuentas por pagar" />
                        <input id="payable" type="number" step="0.01" wire:model.live="payable"
                            class="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring" />
                        <x-input-error :messages="$errors->get('payable')" />
                    </div>

                    <div class="space-y-2">
                        <x-input-label for="loans" value="Préstamos por pagar" />
                        <input id="loans" type="number" step="0.01" wire:model.live="loans"
                            class="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring" />
                        <x-input-error :messages="$errors->get('loans')" />
                    </div>

                    <div class="space-y-2">
                        <x-input-label for="capital" value="Capital social" />
                        <input id="capital" type="number" step="0.01" wire:model.live="capital"
                            class="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring" />
                        <x-input-error :messages="$errors->get('capital')" />
                    </div>
                </div>

                {{-- Capital sugerido --}}
                <div class="bg-muted/20 border border-border rounded-md px-4 py-3 text-sm">
                    Capital sugerido: <span class="font-mono font-semibold">{{ number_format($this->balancingCapital, 2) }}</span>
                </div>

                {{-- Acciones --}}
                <div class="flex justify-end gap-3 border-t border-border pt-4">
                    <x-primary-button type="submit" wire:click="save" wire:loading.attr="disabled">
                        <svg wire:loading wire:target="save" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Registrar apertura
                    </x-primary-button>
                </div>

            </form>
        </div>
    </div>
</div>
