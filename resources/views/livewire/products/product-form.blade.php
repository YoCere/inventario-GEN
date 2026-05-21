<x-modal name="product-form-modal" :title="''" maxWidth="3xl">
    <div class="p-6">
        <!-- Custom Header -->
        <div class="mb-6 space-y-1.5 text-center sm:text-left border-b border-gray-200 pb-4">
            <h3 class="text-lg font-semibold leading-none tracking-tight text-foreground">
                {{ $isEditing ? 'Editar producto' : 'Crear producto' }}
            </h3>
            <p class="text-sm text-muted-foreground">
                {{ $isEditing ? 'Realiza cambios en tu producto aquí. Haz clic en guardar cuando termines.' : 'Agrega un nuevo producto a tu inventario.' }}
            </p>
        </div>

        <form wire:submit="save" class="space-y-6">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- SKU -->
                @if($isEditing)
                    <x-form-input
                        name="sku"
                        label="SKU (Unidad de mantenimiento de stock)"
                        type="text"
                        wire:model="sku"
                        readonly
                        placeholder="ej. SKU-1234-ABCD"
                        class="bg-muted text-muted-foreground cursor-not-allowed"
                    />
                @else
                    <div class="hidden">
                        <input type="hidden" wire:model="sku">
                    </div>
                @endif

                <!-- Name -->
                <x-form-input
                    name="name"
                    label="Nombre del producto"
                    placeholder="ej. Mouse inalámbrico"
                    type="text"
                    wire:model="name"
                    required
                    class="{{ !$isEditing ? 'col-span-2' : '' }}"
                />
            </div>

            <!-- Row 2: Category & Unit -->
            <div class="flex flex-col sm:flex-row gap-6">
                <div class="w-full sm:w-1/2 space-y-2">
                    <x-input-label for="category_id" value="Categoria" required />
                    <div wire:ignore>
                        <x-tom-select
                            id="category_id"
                            name="category_id"
                            wire:model="category_id"
                            :url="route('ajax.categories.search')"
                            method="POST"
                            placeholder="Seleccionar categoría"
                            data-initial-label="{{ $categoryName }}"
                        />
                    </div>
                    <x-input-error :messages="$errors->get('category_id')" />
                </div>

                <div class="w-full sm:w-1/2 space-y-2">
                    <x-input-label for="unit_id" value="Unidad" required />
                    <div wire:ignore>
                        <x-tom-select
                            id="unit_id"
                            name="unit_id"
                            wire:model="unit_id"
                            :url="route('ajax.units.search')"
                            method="POST"
                            placeholder="Seleccionar unidad"
                            data-initial-label="{{ $unitName }}"
                        />
                    </div>
                    <x-input-error :messages="$errors->get('unit_id')" />
                </div>
            </div>

            <!-- Prices -->
            <div class="flex flex-col sm:flex-row gap-6">
                <div class="w-full sm:w-1/2 space-y-2">
                    <x-input-label for="purchase_price" :value="'Precio de compra (' . \App\Models\Setting::get('currency_symbol', 'Rp') . ')'" />
                    <x-currency-input id="purchase_price" wire:model.live.debounce.500ms="purchase_price" placeholder="0" required />
                    <x-input-error :messages="$errors->get('purchase_price')" />
                </div>
                <div class="w-full sm:w-1/2 space-y-2">
                    <x-input-label for="selling_price" :value="'Precio de venta (' . \App\Models\Setting::get('currency_symbol', 'Rp') . ')'" />
                    <x-currency-input id="selling_price" wire:model.live.debounce.500ms="selling_price" placeholder="0" required />
                    <x-input-error :messages="$errors->get('selling_price')" />
                </div>
            </div>

            <!-- Qty, Min Stock, Active -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <x-form-input name="quantity" label="Cantidad" type="number" wire:model="quantity" min="0" placeholder="0" required :disabled="$hasMultiLocationStock" />
                <x-form-input name="min_stock" label="Alerta de stock mínimo" type="number" wire:model="min_stock" min="0" placeholder="0" required />

                <div class="flex items-center h-full pt-8">
                    <label class="inline-flex items-center cursor-pointer">
                        <input type="checkbox" wire:model="is_active" class="w-6 h-6 rounded-full border-2 border-primary text-primary focus:ring-primary/20">
                        <span class="ml-3 text-sm font-medium text-gray-700 dark:text-gray-300">Activo</span>
                    </label>
                </div>
            </div>

            <!-- Visibilidad pública (Tienda en línea) -->
            <div class="rounded-lg border border-border bg-muted/30 p-4 space-y-3">
                <p class="text-sm font-semibold text-foreground flex items-center gap-1.5">
                    <x-heroicon-o-globe-alt class="h-4 w-4" />
                    Tienda en línea
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="flex items-start gap-3 p-3 rounded-md bg-background border border-border cursor-pointer hover:border-primary/50 transition-colors">
                        <input type="checkbox" wire:model="is_public" class="mt-0.5 h-4 w-4 rounded text-primary focus:ring-primary/20">
                        <div class="flex-1">
                            <span class="block text-sm font-medium">Visible en catálogo público</span>
                            <span class="block text-xs text-muted-foreground">Aparece en /tienda si la tienda está activada.</span>
                        </div>
                    </label>
                    <label class="flex items-start gap-3 p-3 rounded-md bg-background border border-border cursor-pointer hover:border-primary/50 transition-colors">
                        <input type="checkbox" wire:model="featured" class="mt-0.5 h-4 w-4 rounded text-primary focus:ring-primary/20">
                        <div class="flex-1">
                            <span class="block text-sm font-medium">⭐ Destacado</span>
                            <span class="block text-xs text-muted-foreground">Aparece arriba en el catálogo con un badge.</span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Ubicación -->
            <div class="space-y-2">
                <x-input-label for="location_id" value="Ubicación en almacén" />
                @if($hasMultiLocationStock)
                    <div class="rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800">
                        ⚠️ Este producto tiene stock en múltiples ubicaciones. Gestiona stock por ubicación desde
                        <a href="{{ route('locations.index') }}" class="underline font-semibold">Ubicaciones</a>.
                    </div>
                @else
                    <select id="location_id" wire:model="location_id" class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                        <option value="">— Ubicación default —</option>
                        @foreach($locations as $loc)
                            <option value="{{ $loc->id }}">
                                {{ $loc->warehouse?->name }} › {{ $loc->name }}{{ $loc->is_default ? ' (default)' : '' }}
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500">Dónde se almacena físicamente el producto.</p>
                    <x-input-error :messages="$errors->get('location_id')" />
                @endif
            </div>

            <!-- Description -->
            <div class="space-y-2">
                <x-input-label for="description" value="Descripción" />
                <textarea id="description" wire:model="description" rows="3" class="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm" placeholder="Descripción visible en el catálogo público..."></textarea>
                <x-input-error :messages="$errors->get('description')" />
            </div>

            <!-- Notes -->
            <div class="space-y-2">
                <x-input-label for="notes" value="Notas internas" />
                <textarea id="notes" wire:model="notes" rows="2" class="flex min-h-[60px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm" placeholder="Historial de precios interno y notas..."></textarea>
                <x-input-error :messages="$errors->get('notes')" />
            </div>

            <!-- Galería de imágenes -->
            <div class="space-y-3 rounded-lg border border-border p-4 bg-muted/20">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-semibold text-foreground flex items-center gap-1.5">
                        <x-heroicon-o-photo class="h-4 w-4" />
                        Imágenes del producto
                    </p>
                    <p class="text-xs text-muted-foreground">Máx 10 imágenes. JPG/PNG/WebP, hasta 4MB c/u.</p>
                </div>

                <!-- Galería existente (solo en edición) -->
                @if($isEditing && $product && $product->images && $product->images->count() > 0)
                    <div>
                        <p class="text-xs text-muted-foreground mb-2">Imágenes actuales (click ⭐ para marcar principal, ✕ para borrar):</p>
                        <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-5 gap-2">
                            @foreach($product->images as $img)
                                @php
                                    $thumbUrl = $img->path_card ? \Illuminate\Support\Facades\Storage::url($img->path_card) : ($img->path ? \Illuminate\Support\Facades\Storage::url($img->path) : asset('images/placeholder-product.svg'));
                                    $markedDelete = in_array($img->id, $imagesToDelete, true);
                                    $isPrimary = $primaryImageId === $img->id;
                                @endphp
                                <div class="relative group aspect-square rounded-lg overflow-hidden border-2 {{ $markedDelete ? 'border-red-400 opacity-40' : ($isPrimary ? 'border-primary' : 'border-border') }}">
                                    <img src="{{ $thumbUrl }}" alt="" class="w-full h-full object-cover">

                                    <div class="absolute inset-0 flex flex-col items-center justify-center gap-1 bg-black/0 group-hover:bg-black/40 transition-colors">
                                        <div class="opacity-0 group-hover:opacity-100 flex gap-1 transition-opacity">
                                            <button type="button" wire:click="markPrimary({{ $img->id }})"
                                                    @disabled($markedDelete)
                                                    title="Marcar como principal"
                                                    class="w-7 h-7 rounded-full bg-white/90 text-yellow-500 hover:bg-white shadow flex items-center justify-center text-sm">
                                                ⭐
                                            </button>
                                            <button type="button" wire:click="toggleDeleteImage({{ $img->id }})"
                                                    title="{{ $markedDelete ? 'Restaurar' : 'Eliminar' }}"
                                                    class="w-7 h-7 rounded-full bg-white/90 hover:bg-white shadow flex items-center justify-center text-sm {{ $markedDelete ? 'text-green-600' : 'text-red-500' }}">
                                                {{ $markedDelete ? '↺' : '✕' }}
                                            </button>
                                        </div>
                                    </div>

                                    @if($isPrimary)
                                        <span class="absolute top-1 left-1 bg-primary text-white text-[10px] font-bold px-1.5 py-0.5 rounded">PRIMARY</span>
                                    @endif
                                    @if($markedDelete)
                                        <span class="absolute bottom-1 left-1 right-1 bg-red-500 text-white text-[10px] font-bold text-center py-0.5 rounded">SE BORRARÁ</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <!-- Upload nuevo (múltiple) -->
                <div>
                    <label for="gallery-upload" class="block cursor-pointer">
                        <div class="border-2 border-dashed border-border rounded-lg p-6 text-center hover:border-primary/50 hover:bg-background transition-colors">
                            <x-heroicon-o-arrow-up-tray class="h-7 w-7 mx-auto text-muted-foreground" />
                            <p class="mt-2 text-sm font-medium text-foreground">Click o arrastra imágenes aquí</p>
                            <p class="text-xs text-muted-foreground mt-0.5">Se generan automáticamente versiones optimizadas (WebP).</p>
                        </div>
                    </label>
                    <input id="gallery-upload"
                           type="file"
                           wire:model="gallery"
                           multiple
                           accept="image/jpeg,image/png,image/webp"
                           class="hidden">

                    <div wire:loading wire:target="gallery" class="text-xs text-blue-600 mt-2 flex items-center gap-1.5">
                        <svg class="animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        Subiendo imágenes…
                    </div>
                    @error('gallery.*')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                    @error('gallery')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Preview uploads nuevos -->
                @if(!empty($gallery))
                    <div>
                        <p class="text-xs text-muted-foreground mb-2">Imágenes a añadir ({{ count($gallery) }}):</p>
                        <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-5 gap-2">
                            @foreach($gallery as $idx => $upload)
                                @if($upload)
                                    <div class="relative aspect-square rounded-lg overflow-hidden border-2 border-dashed border-green-400">
                                        <img src="{{ $upload->temporaryUrl() }}" alt="Preview" class="w-full h-full object-cover">
                                        <button type="button" wire:click="removeNewUpload({{ $idx }})"
                                                title="Quitar"
                                                class="absolute top-1 right-1 w-6 h-6 rounded-full bg-white/95 text-red-500 hover:bg-white shadow flex items-center justify-center text-xs">
                                            ✕
                                        </button>
                                        <span class="absolute bottom-1 left-1 bg-green-500 text-white text-[10px] font-bold px-1.5 py-0.5 rounded">NUEVO</span>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <!-- Actions -->
            <div class="mt-6 flex justify-end gap-3 border-t pt-4 border-gray-200">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', { name: 'product-form-modal' })">
                    Cancelar
                </x-secondary-button>

                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="save">
                    <svg wire:loading wire:target="save" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <x-heroicon-o-check wire:loading.remove wire:target="save" class="w-4 h-4 mr-2" />
                    {{ $isEditing ? 'Guardar cambios' : 'Crear producto' }}
                </x-primary-button>
            </div>
        </form>
    </div>
</x-modal>
