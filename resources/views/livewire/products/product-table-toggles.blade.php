<div class="flex flex-wrap gap-2 mb-3">
    <button type="button" wire:click="$toggle('onlyMissingPrice')"
        @class([
            'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium border transition-colors',
            'bg-yellow-100 border-yellow-400 text-yellow-800' => $onlyMissingPrice,
            'bg-white border-gray-300 text-gray-700 hover:bg-gray-50' => ! $onlyMissingPrice,
        ])>
        <x-heroicon-o-banknotes class="w-4 h-4" />
        Sin precio de venta
    </button>
    <button type="button" wire:click="$toggle('onlyMissingPhoto')"
        @class([
            'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium border transition-colors',
            'bg-amber-100 border-amber-400 text-amber-800' => $onlyMissingPhoto,
            'bg-white border-gray-300 text-gray-700 hover:bg-gray-50' => ! $onlyMissingPhoto,
        ])>
        <x-heroicon-o-photo class="w-4 h-4" />
        Sin foto
    </button>
</div>

{{-- Edición masiva de precios: aparece al seleccionar filas con el checkbox --}}
@if(count($checkboxValues) > 0)
    <div class="mb-3 flex flex-wrap items-end gap-2 rounded-lg border border-indigo-200 bg-indigo-50 p-3">
        <span class="self-center text-sm font-semibold text-indigo-800">{{ count($checkboxValues) }} seleccionado(s)</span>

        <div class="space-y-1">
            <label class="block text-xs text-indigo-700">Operación</label>
            <select wire:model="bulkMode" class="h-9 rounded-md border border-indigo-300 bg-white px-2 text-sm">
                <option value="set">Fijar precio</option>
                <option value="inc_pct">Subir %</option>
                <option value="dec_pct">Bajar %</option>
                <option value="inc_amt">Subir Bs</option>
                <option value="dec_amt">Bajar Bs</option>
            </select>
        </div>

        <div class="space-y-1">
            <label class="block text-xs text-indigo-700">Precio</label>
            <select wire:model="bulkTarget" class="h-9 rounded-md border border-indigo-300 bg-white px-2 text-sm">
                <option value="selling">Venta</option>
                <option value="purchase">Compra</option>
            </select>
        </div>

        <div class="space-y-1">
            <label class="block text-xs text-indigo-700">Valor</label>
            <input type="number" step="0.01" min="0" wire:model="bulkValue" placeholder="0.00"
                   class="h-9 w-28 rounded-md border border-indigo-300 bg-white px-2 text-sm">
        </div>

        <button type="button" wire:click="applyBulkPrice"
                wire:confirm="¿Aplicar el cambio de precio a los {{ count($checkboxValues) }} productos seleccionados?"
                class="inline-flex h-9 items-center gap-1.5 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700">
            Aplicar
        </button>
    </div>
@endif
