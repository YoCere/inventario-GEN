<x-modal name="product-form-modal" :title="''" maxWidth="2xl">
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
                    <!-- SKU Auto Generated -->
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
                <!-- Category -->
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

                <!-- Unit -->
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

            <!-- Prices (Forced Inline) -->
            <div class="flex flex-col sm:flex-row gap-6">
                <!-- Purchase Price -->
                <div class="w-full sm:w-1/2 space-y-2">
                    <x-input-label for="purchase_price" :value="'Precio de compra (' . \App\Models\Setting::get('currency_symbol', 'Rp') . ')'" />
                    <x-currency-input
                        id="purchase_price"
                        wire:model.live.debounce.500ms="purchase_price"
                        placeholder="0"
                        required
                    />
                    <x-input-error :messages="$errors->get('purchase_price')" />
                </div>

                <!-- Selling Price -->
                <div class="w-full sm:w-1/2 space-y-2">
                    <x-input-label for="selling_price" :value="'Precio de venta (' . \App\Models\Setting::get('currency_symbol', 'Rp') . ')'" />
                    <x-currency-input
                        id="selling_price"
                        wire:model.live.debounce.500ms="selling_price"
                        placeholder="0"
                        required
                    />
                    <x-input-error :messages="$errors->get('selling_price')" />
                </div>
            </div>

            <!-- Row 5: Qty, Min Stock, Active -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Quantity -->
                <x-form-input
                    name="quantity"
                    label="Cantidad"
                    type="number"
                    wire:model="quantity"
                    min="0"
                    placeholder="0"
                    required
                />

                <!-- Min Stock -->
                <x-form-input
                    name="min_stock"
                    label="Alerta de stock mínimo"
                    type="number"
                    wire:model="min_stock"
                    min="0"
                    placeholder="0"
                    required
                />

                <!-- Is Active -->
                <div class="flex items-center h-full pt-8">
                    <label class="inline-flex items-center cursor-pointer">
                        <input
                            type="checkbox"
                            wire:model="is_active"
                            class="w-6 h-6 rounded-full border-2 border-primary text-primary focus:ring-primary/20"
                        >
                        <span class="ml-3 text-sm font-medium text-gray-700 dark:text-gray-300">
                            Activo
                        </span>
                    </label>
                </div>
            </div>

            <!-- Description -->
            <div class="space-y-2">
                <x-input-label for="description" value="Descripción" />
                <textarea
                    id="description"
                    wire:model="description"
                    rows="3"
                    class="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                    placeholder="Descripción opcional..."
                ></textarea>
                <x-input-error :messages="$errors->get('description')" />
            </div>

            <!-- Notes -->
            <div class="space-y-2">
                <x-input-label for="notes" value="Notas internas" />
                <textarea
                    id="notes"
                    wire:model="notes"
                    rows="3"
                    class="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                    placeholder="Historial de precios interno y notas..."
                ></textarea>
                <x-input-error :messages="$errors->get('notes')" />
            </div>

            <!-- Photo -->
            <div class="space-y-4">
                <div>
                    <x-input-label for="photo" value="Foto del producto" />
                    <div class="mt-2">
                        <input
                            type="file"
                            wire:model="photo"
                            id="photo"
                            accept="image/jpeg,image/png,image/webp"
                            class="block w-full text-sm text-gray-500
                            file:mr-4 file:py-2 file:px-4
                            file:rounded-md file:border-0
                            file:text-sm file:font-semibold
                            file:bg-indigo-50 file:text-indigo-700
                            hover:file:bg-indigo-100"
                        />
                        <p class="text-xs text-gray-500 mt-1">JPG, PNG o WebP. Máximo 1MB.</p>
                    </div>
                    <x-input-error :messages="$errors->get('photo')" class="mt-2" />
                </div>

                <!-- Preview de imagen temporal -->
                @if($photo)
                    <div class="mt-4">
                        <p class="text-sm font-medium text-gray-700 mb-2">Vista previa:</p>
                        <img src="{{ $photo->temporaryUrl() }}" alt="Preview" class="h-48 w-48 object-cover rounded-md border border-gray-200">
                    </div>
                @elseif($isEditing && $product && $product->image_path)
                    <div class="mt-4">
                        <p class="text-sm font-medium text-gray-700 mb-2">Imagen actual:</p>
                        <img src="{{ $product->image_url }}" alt="{{ $product->name }}" class="h-48 w-48 object-cover rounded-md border border-gray-200">
                    </div>
                @endif
            </div>

            <!-- Actions -->
            <div class="mt-6 flex justify-end gap-3 border-t pt-4 border-gray-200">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', { name: 'product-form-modal' })">
                    Cancelar
                </x-secondary-button>

                <x-primary-button type="submit" wire:loading.attr="disabled">
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
