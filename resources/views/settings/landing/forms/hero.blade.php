<div class="space-y-4">
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Título</label>
        <input type="text" wire:model="form.heading" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.heading') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Subtítulo</label>
        <input type="text" wire:model="form.subheading" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.subheading') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div class="grid sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-foreground mb-1">Texto del botón</label>
            <input type="text" wire:model="form.cta_text" class="w-full rounded-md border-input bg-background text-sm">
            @error('form.cta_text') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-foreground mb-1">El botón lleva a</label>
            @include('settings.landing.forms.partials.target', ['field' => 'form.cta_target', 'current' => $form['cta_target'] ?? 'catalog'])
        </div>
    </div>

    @include('settings.landing.forms.partials.single-image', [
        'field' => 'background_image_path',
        'label' => 'Imagen de fondo',
        'help' => 'Opcional. Si no cargás ninguna, se usa el degradado con los colores de la tienda.',
    ])
</div>
