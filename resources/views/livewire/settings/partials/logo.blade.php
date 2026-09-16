<div class="flex items-center gap-4">
    <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-dashed border-border bg-muted/40 p-2">
        @if($url)
            <img src="{{ $url }}" alt="{{ $label }} actual" class="max-h-full max-w-full object-contain">
        @else
            <x-heroicon-o-photo class="h-7 w-7 text-muted-foreground/50" />
        @endif
    </div>
    <div class="min-w-0 space-y-2">
        <p class="text-sm font-medium text-foreground">{{ $label }}</p>
        <div class="flex flex-wrap items-center gap-2">
            <label for="upload-{{ $model }}"
                   class="inline-flex h-9 cursor-pointer items-center gap-2 rounded-md border border-input bg-background px-3 text-sm font-medium hover:bg-accent hover:text-accent-foreground">
                <x-heroicon-o-arrow-up-tray class="h-4 w-4" />
                {{ $url ? 'Cambiar' : 'Subir logo' }}
            </label>
            {{-- .stop: el logo se guarda solo, no debe marcar el formulario como modificado --}}
            <input id="upload-{{ $model }}" type="file" wire:model="{{ $model }}" class="hidden"
                   x-on:input.stop x-on:change.stop
                   accept="image/png,image/jpeg,image/svg+xml,image/webp">
            @if($url)
                <button type="button" wire:click="{{ $remove }}" wire:confirm="¿Quitar el logo?"
                        class="h-9 rounded-md px-3 text-sm font-medium text-muted-foreground hover:bg-muted hover:text-foreground">
                    Quitar
                </button>
            @endif
            <span wire:loading wire:target="{{ $model }}" class="text-sm text-muted-foreground">Subiendo…</span>
        </div>
        <p class="text-sm text-muted-foreground">{{ $help }}</p>
        @error($model)
            <p class="text-sm text-destructive">{{ $message }}</p>
        @enderror
    </div>
</div>
