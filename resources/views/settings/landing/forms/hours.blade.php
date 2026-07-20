<div class="space-y-4">
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Título</label>
        <input type="text" wire:model="form.heading" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.heading') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div class="space-y-2">
        <label class="block text-sm font-medium text-foreground">Horarios</label>
        @foreach(($form['rows'] ?? []) as $i => $row)
            <div class="flex items-center gap-2" wire:key="hours-row-{{ $i }}">
                <input type="text" wire:model="form.rows.{{ $i }}.label" placeholder="Lunes a Viernes"
                       class="flex-1 rounded-md border-input bg-background text-sm">
                <input type="text" wire:model="form.rows.{{ $i }}.value" placeholder="9:00 – 18:00"
                       class="flex-1 rounded-md border-input bg-background text-sm">
                <button type="button" wire:click="removeRow('rows', {{ $i }})"
                        class="px-2 text-muted-foreground hover:text-red-600" title="Quitar">✕</button>
            </div>
            @error('form.rows.' . $i . '.label') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            @error('form.rows.' . $i . '.value') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
        @endforeach

        <x-secondary-button type="button" wire:click="addHoursRow">+ Agregar horario</x-secondary-button>
    </div>
</div>
