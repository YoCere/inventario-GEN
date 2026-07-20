<div class="rounded-lg border border-border bg-background p-4 space-y-4">
    <div>
        <h3 class="text-sm font-semibold text-foreground">Cómo se ve al compartir</h3>
        <p class="text-xs text-muted-foreground">
            Lo que aparece cuando alguien pega el enlace de tu tienda en WhatsApp o redes.
            Si dejás los campos vacíos, se usa el nombre del negocio y el texto del héroe.
        </p>
    </div>

    <div class="grid lg:grid-cols-2 gap-4">
        <div class="space-y-3">
            <div>
                <label class="block text-sm font-medium text-foreground mb-1">Título</label>
                <input type="text" wire:model="title" maxlength="70"
                       class="w-full rounded-md border-input bg-background text-sm">
                @error('title') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-foreground mb-1">Descripción</label>
                <textarea wire:model="description" rows="3" maxlength="200"
                          class="w-full rounded-md border-input bg-background text-sm"></textarea>
                @error('description') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-foreground mb-1">Imagen</label>
                <p class="text-xs text-muted-foreground mb-2">
                    Recomendado: 1200 × 630 píxeles, menos de 300 KB. Si no cargás ninguna, se usa
                    el fondo del héroe y, si tampoco hay, el logo.
                </p>
                <input type="file" wire:model="imageUpload" accept="image/png,image/jpeg,image/webp"
                       class="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border file:border-input file:bg-background file:px-3 file:py-1.5 file:text-sm">
                <div wire:loading wire:target="imageUpload" class="text-xs text-blue-600 mt-1">Subiendo…</div>
                @error('imageUpload') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror

                @if($this->imagePath())
                    <x-secondary-button type="button" wire:click="removeImage" class="mt-2">
                        Quitar imagen
                    </x-secondary-button>
                @endif
            </div>

            <div class="flex items-center gap-2">
                <x-primary-button type="button" wire:click="save">Guardar</x-primary-button>
                <span wire:loading wire:target="save" class="text-xs text-muted-foreground">Guardando…</span>
                <span x-data="{ ok: false }" x-on:share-settings-saved.window="ok = true; setTimeout(() => ok = false, 2000)"
                      x-show="ok" x-cloak class="text-xs text-green-600">Guardado</span>
            </div>
        </div>

        {{-- Vista previa: aproximación de cómo lo muestra WhatsApp --}}
        <div>
            <p class="text-xs font-medium text-muted-foreground mb-2">Vista previa</p>

            @if($this->preview)
                <div class="max-w-sm rounded-lg border border-border overflow-hidden bg-muted/30">
                    @if($this->preview->imageUrl)
                        <img src="{{ $this->preview->imageUrl }}" alt=""
                             class="w-full aspect-[1200/630] object-cover bg-muted">
                    @else
                        <div class="w-full aspect-[1200/630] flex items-center justify-center bg-muted text-xs text-muted-foreground">
                            Sin imagen
                        </div>
                    @endif
                    <div class="p-3">
                        <p class="text-sm font-semibold text-foreground truncate">{{ $this->preview->title }}</p>
                        <p class="text-xs text-muted-foreground line-clamp-2">{{ $this->preview->description }}</p>
                        <p class="text-[11px] text-muted-foreground mt-1 truncate">{{ parse_url($this->preview->url, PHP_URL_HOST) }}</p>
                    </div>
                </div>
                <p class="text-xs text-muted-foreground mt-2">
                    WhatsApp guarda estas vistas previas por un tiempo: un enlace ya compartido puede seguir
                    mostrando la versión anterior.
                </p>
            @else
                <div class="max-w-sm rounded-lg border border-dashed border-border p-4 text-xs text-muted-foreground">
                    Activá la tienda para ver la vista previa de cómo se comparte el enlace.
                </div>
            @endif
        </div>
    </div>
</div>
