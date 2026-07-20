<div class="space-y-4">
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Título</label>
        <input type="text" wire:model="form.heading" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.heading') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Texto</label>
        <input type="text" wire:model="form.text" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.text') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>
    <div class="grid sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-foreground mb-1">Texto del botón</label>
            <input type="text" wire:model="form.button_text" class="w-full rounded-md border-input bg-background text-sm">
            @error('form.button_text') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-foreground mb-1">El botón lleva a</label>
            @include('settings.landing.forms.partials.target', ['field' => 'form.target', 'current' => $form['target'] ?? 'catalog'])
        </div>
    </div>
</div>
