<x-modal name="product-detail-modal" focusable>
    @if($product)
        <div class="p-6 space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between border-b border-border pb-4">
                <div>
                    <h3 class="text-xl font-bold text-foreground tracking-tight">{{ $product->name }}</h3>
                    <p class="text-sm text-muted-foreground font-mono">{{ $product->sku }}</p>
                </div>
                <div>
                    @if($product->is_active)
                        <span class="inline-flex items-center rounded-full bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">
                            Activo
                        </span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/20">
                            Inactivo
                        </span>
                    @endif
                </div>
            </div>

            <!-- Product Image -->
            @if($product->hasDisplayImage())
                <div class="flex justify-center">
                    <img src="{{ $product->image_url }}" alt="{{ $product->name }}" class="h-64 w-64 object-cover rounded-lg border border-border shadow-sm">
                </div>
            @endif

            <div class="space-y-6">
                <!-- Details -->
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label class="text-sm font-medium leading-none text-muted-foreground">Categoria</label>
                        <p class="text-sm text-foreground font-medium">{{ $product->category->name ?? '-' }}</p>
                    </div>

                    <div class="space-y-1">
                        <label class="text-sm font-medium leading-none text-muted-foreground">Unidad</label>
                        <p class="text-sm text-foreground font-medium">
                            @if($product->unit)
                                {{ $product->unit->name }} <span class="text-muted-foreground">({{ $product->unit->symbol }})</span>
                            @else
                                -
                            @endif
                        </p>
                    </div>

                    <div class="space-y-1">
                        <label class="text-sm font-medium leading-none text-muted-foreground">Precio de venta</label>
                        <p class="text-sm text-foreground font-medium">@money($product->selling_price)</p>
                    </div>

                    <div class="space-y-1">
                        <label class="text-sm font-medium leading-none text-muted-foreground">Precio de compra</label>
                        <p class="text-sm text-foreground font-medium">@money($product->purchase_price)</p>
                    </div>

                    <div class="space-y-1">
                        <label class="text-sm font-medium leading-none text-muted-foreground">Stock</label>
                        <p class="text-sm text-foreground font-medium {{ $product->quantity <= $product->min_stock ? 'text-red-500' : '' }}">
                            {{ $product->quantity . ' ' . ($product->unit->symbol ?? '') }}
                        </p>
                    </div>

                    <div class="space-y-1">
                        <label class="text-sm font-medium leading-none text-muted-foreground">Alerta de stock minimo</label>
                        <p class="text-sm text-foreground font-medium">{{ $product->min_stock }}</p>
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="text-sm font-medium leading-none text-muted-foreground">Descripcion</label>
                    <p class="text-sm text-foreground font-medium">
                        {{ $product->description ?: 'No se proporcionó descripción.' }}
                    </p>
                </div>

                <div class="space-y-1">
                    <label class="text-sm font-medium leading-none text-muted-foreground">Notas internas</label>
                    <div class="bg-gray-50 border border-secondary p-3 rounded-md">
                        <p class="text-sm text-foreground font-mono whitespace-pre-wrap leading-relaxed">{{ $product->notes ?: 'Sin notas.' }}</p>
                    </div>
                </div>

                <!-- Meta -->
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label class="text-sm font-medium leading-none text-muted-foreground">Creado el</label>
                        <p class="text-sm text-foreground font-medium">{{ $product->created_at?->format('d M Y, H:i') ?? '-' }}</p>
                    </div>

                    <div class="space-y-1">
                        <label class="text-sm font-medium leading-none text-muted-foreground">Ultima actualizacion</label>
                        <p class="text-sm text-foreground font-medium">{{ $product->updated_at?->format('d M Y, H:i') ?? '-' }}</p>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-x-2 pt-4 border-t border-border">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', { name: 'product-detail-modal' })">
                    Cerrar
                </x-secondary-button>
                <x-primary-button type="button" x-on:click="$dispatch('close-modal', { name: 'product-detail-modal' }); $dispatch('edit-product', { product: {{ $product->id }} })">
                    <x-heroicon-o-pencil-square class="w-4 h-4 mr-2" />
                    Editar producto
                </x-primary-button>
            </div>
        </div>
    @else
        <div class="p-8 text-center flex flex-col items-center justify-center space-y-3">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
            <span class="text-sm text-muted-foreground">Cargando detalles...</span>
        </div>
    @endif
</x-modal>
