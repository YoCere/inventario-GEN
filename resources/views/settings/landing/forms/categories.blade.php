<div class="space-y-4">
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Título</label>
        <input type="text" wire:model="form.heading" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.heading') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Qué mostrar</label>
        <select wire:model.live="form.source" class="w-full rounded-md border-input bg-background text-sm">
            <option value="auto">Las categorías con productos publicados (automático)</option>
            <option value="manual">Una lista que escribo yo</option>
        </select>
        @error('form.source') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    @if(($form['source'] ?? 'auto') === 'manual')
        <div class="space-y-2">
            <label class="block text-sm font-medium text-foreground">Elementos</label>
            @foreach(($form['items'] ?? []) as $i => $item)
                <div class="flex items-center gap-2" wire:key="cat-item-{{ $i }}">
                    <input type="text" wire:model="form.items.{{ $i }}.label" placeholder="Nombre"
                           class="flex-1 rounded-md border-input bg-background text-sm">
                    <input type="text" wire:model="form.items.{{ $i }}.link" placeholder="https://… o /pagina"
                           class="flex-1 rounded-md border-input bg-background text-sm">
                    <button type="button" wire:click="removeRow('items', {{ $i }})"
                            class="px-2 text-muted-foreground hover:text-red-600" title="Quitar">✕</button>
                </div>
                @error('form.items.' . $i . '.label') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                @error('form.items.' . $i . '.link') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            @endforeach

            <x-secondary-button type="button" wire:click="addCategoryItem">+ Agregar elemento</x-secondary-button>
        </div>
    @endif
</div>
