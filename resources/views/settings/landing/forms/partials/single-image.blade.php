{{-- Imagen única. $field = clave dentro de $form (ej. 'background_image_path'), $label, $help --}}
<div>
    <label class="block text-sm font-medium text-foreground mb-1">{{ $label }}</label>
    @if($help)<p class="text-xs text-muted-foreground mb-2">{{ $help }}</p>@endif

    @if(! empty($form[$field]))
        <div class="flex items-center gap-3 mb-2">
            <img src="{{ \Illuminate\Support\Facades\Storage::url($form[$field]) }}" alt=""
                 class="h-16 w-16 rounded-md object-cover border border-border">
            <x-secondary-button type="button" wire:click="removeImage('{{ $field }}')">Quitar</x-secondary-button>
        </div>
    @endif

    <input type="file" wire:model="imageUpload.{{ $field }}" accept="image/png,image/jpeg,image/webp"
           class="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border file:border-input file:bg-background file:px-3 file:py-1.5 file:text-sm">
    <div wire:loading wire:target="imageUpload.{{ $field }}" class="text-xs text-blue-600 mt-1">Subiendo…</div>
    @error('imageUpload.' . $field) <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
</div>
