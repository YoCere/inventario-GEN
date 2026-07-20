<div class="space-y-4">
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Título</label>
        <input type="text" wire:model="form.heading" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.heading') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Texto</label>

        {{-- wire:ignore es obligatorio: sin él, el re-render de Livewire le pisa el DOM a Trix.
             El wire:key con el id de la sección fuerza a recrear el editor al cambiar de sección. --}}
        <div wire:ignore wire:key="trix-{{ $sectionId }}">
            <input id="body-html-{{ $sectionId }}" type="hidden" value="{{ $form['body_html'] ?? '' }}">
            <trix-editor
                input="body-html-{{ $sectionId }}"
                x-data
                x-on:trix-change="$wire.set('form.body_html', $event.target.value, false)"
                class="trix-content rounded-md border border-input bg-background text-sm"></trix-editor>
        </div>

        @error('form.body_html') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        <p class="text-xs text-muted-foreground mt-1">
            El formato se limpia al guardar: se permiten negrita, cursiva, listas, títulos y enlaces.
        </p>
    </div>

    @include('settings.landing.forms.partials.single-image', [
        'field' => 'image_path',
        'label' => 'Imagen',
        'help' => 'Opcional. Se muestra al costado del texto.',
    ])
</div>
