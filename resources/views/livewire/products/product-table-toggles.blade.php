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
