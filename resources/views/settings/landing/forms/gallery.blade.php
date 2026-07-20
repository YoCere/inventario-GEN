<div class="space-y-4">
    <div>
        <label class="block text-sm font-medium text-foreground mb-1">Título</label>
        <input type="text" wire:model="form.heading" class="w-full rounded-md border-input bg-background text-sm">
        @error('form.heading') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-medium text-foreground mb-2">Fotos</label>
        @if(! empty($form['images']))
            <div class="grid grid-cols-3 sm:grid-cols-4 gap-2 mb-3">
                @foreach($form['images'] as $i => $img)
                    <div class="relative" wire:key="gal-{{ $i }}">
                        <img src="{{ \Illuminate\Support\Facades\Storage::url($img) }}" alt=""
                             class="aspect-square w-full rounded-md object-cover border border-border">
                        <button type="button" wire:click="removeGalleryImage({{ $i }})"
                                class="absolute top-1 right-1 rounded-full bg-background/90 border border-border px-1.5 text-xs hover:text-red-600"
                                title="Quitar">✕</button>
                    </div>
                @endforeach
            </div>
        @endif

        <input type="file" wire:model="galleryUpload" accept="image/png,image/jpeg,image/webp"
               class="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border file:border-input file:bg-background file:px-3 file:py-1.5 file:text-sm">
        <div wire:loading wire:target="galleryUpload" class="text-xs text-blue-600 mt-1">Subiendo…</div>
        @error('galleryUpload') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>
</div>
