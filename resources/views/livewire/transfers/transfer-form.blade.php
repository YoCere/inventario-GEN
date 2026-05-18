<x-modal name="transfer-form-modal" :title="''" maxWidth="3xl">
    <div class="p-6">
        <div class="mb-6 space-y-1.5 text-center sm:text-left border-b border-gray-200 pb-4">
            <h3 class="text-lg font-semibold leading-none tracking-tight text-foreground">
                Nueva transferencia de stock
            </h3>
            <p class="text-sm text-muted-foreground">
                Mover productos entre ubicaciones. Se crea en borrador, ejecuta para aplicar el movimiento.
            </p>
        </div>

        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="space-y-2">
                    <x-input-label for="from_location_id" value="Desde" required />
                    <select id="from_location_id" wire:model.live="from_location_id"
                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                        <option value="">Seleccionar origen...</option>
                        @foreach($locations as $loc)
                            <option value="{{ $loc->id }}">{{ $loc->warehouse?->name }} › {{ $loc->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('from_location_id')" />
                </div>

                <div class="space-y-2">
                    <x-input-label for="to_location_id" value="Hacia" required />
                    <select id="to_location_id" wire:model="to_location_id"
                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                        <option value="">Seleccionar destino...</option>
                        @foreach($locations as $loc)
                            <option value="{{ $loc->id }}" @disabled($loc->id === $from_location_id)>
                                {{ $loc->warehouse?->name }} › {{ $loc->name }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('to_location_id')" />
                </div>
            </div>

            <!-- Add items -->
            @if($from_location_id)
                <div class="rounded-md border border-gray-200 p-4 space-y-3">
                    <h4 class="text-sm font-semibold">Agregar productos</h4>
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                        <div class="md:col-span-7">
                            <select wire:model="newProductId"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                                <option value="">Seleccionar producto...</option>
                                @foreach($availableProducts as $p)
                                    <option value="{{ $p['id'] }}">
                                        {{ $p['name'] }} ({{ $p['sku'] }}) — disp: {{ $p['available'] }}
                                    </option>
                                @endforeach
                            </select>
                            @if($availableProducts->isEmpty())
                                <p class="text-xs text-amber-600 mt-1">No hay productos con stock en la ubicación origen.</p>
                            @endif
                        </div>
                        <div class="md:col-span-3">
                            <input type="number" wire:model="newQuantity" min="1"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                placeholder="Cantidad">
                        </div>
                        <div class="md:col-span-2">
                            <button type="button" wire:click="addItem"
                                class="w-full h-10 rounded-md bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700">
                                + Agregar
                            </button>
                        </div>
                    </div>
                </div>
            @else
                <div class="rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800">
                    Selecciona la ubicación origen para ver productos disponibles.
                </div>
            @endif

            <!-- Items list -->
            @if(!empty($items))
                <div class="rounded-md border border-gray-200">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-xs uppercase">
                            <tr>
                                <th class="px-3 py-2 text-left">Producto</th>
                                <th class="px-3 py-2 text-right">Cantidad</th>
                                <th class="px-3 py-2 w-12"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @foreach($items as $idx => $item)
                                <tr>
                                    <td class="px-3 py-2">
                                        <div class="font-medium">{{ $item['product_name'] }}</div>
                                        <div class="text-xs text-gray-500">{{ $item['product_sku'] }}</div>
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono">{{ $item['quantity'] }}</td>
                                    <td class="px-3 py-2">
                                        <button type="button" wire:click="removeItem({{ $idx }})"
                                            class="text-red-500 hover:text-red-700">×</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <x-input-error :messages="$errors->get('items')" />

            <div class="space-y-2">
                <x-input-label for="notes" value="Notas (opcional)" />
                <textarea id="notes" wire:model="notes" rows="2"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    placeholder="Motivo, observaciones..."></textarea>
            </div>

            <div class="mt-6 flex justify-end gap-3 border-t border-gray-200 pt-4">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', { name: 'transfer-form-modal' })">
                    {{ __('Cancelar') }}
                </x-secondary-button>
                <x-primary-button type="submit" wire:loading.attr="disabled">
                    <x-heroicon-o-check class="w-4 h-4 mr-2" />
                    Crear transferencia (borrador)
                </x-primary-button>
            </div>
        </form>
    </div>
</x-modal>
