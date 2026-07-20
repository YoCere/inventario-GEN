<div class="space-y-4">
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Título</label>
        <input type="text" wire:model="form.heading" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.heading') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Texto</label>
        {{-- El editor de texto rico (Trix) se monta en una tarea posterior. --}}
        <textarea wire:model="form.body_html" rows="8"
                  class="w-full rounded-md border-input bg-background text-sm font-mono"></textarea>
        @error('form.body_html') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    @include('settings.landing.forms.partials.single-image', [
        'field' => 'image_path',
        'label' => 'Imagen',
        'help' => 'Opcional. Se muestra al costado del texto.',
    ])
</div>
