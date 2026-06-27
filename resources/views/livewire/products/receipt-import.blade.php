<div>
    <x-modal name="receipt-import-modal" :title="''" maxWidth="4xl">
        <div class="p-6 space-y-5">
            <div>
                <h2 class="text-lg font-semibold text-foreground">Importar productos desde recibo</h2>
                <p class="text-sm text-muted-foreground mt-0.5">
                    Sube una foto del recibo; la IA extrae los productos. Revisa, ajusta categoría/unidad y crea.
                    El precio de venta queda vacío para completarlo luego.
                </p>
            </div>

            {{-- Subida + analizar --}}
            <div class="space-y-2">
                <x-input-label for="receipt-file" value="Foto del recibo" />
                <input
                    id="receipt-file"
                    type="file"
                    wire:model="receipt"
                    accept="image/*"
                    data-heic-aware
                    class="block w-full text-sm text-gray-500
                        file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0
                        file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700
                        hover:file:bg-indigo-100"
                />
                @error('receipt') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

                {{-- Cámara directa (móvil): capture="environment" abre cámara trasera.
                     Comparte wire:model="receipt", así Livewire sube la foto igual que el selector. --}}
                <input
                    id="receipt-camera"
                    type="file"
                    wire:model="receipt"
                    accept="image/*"
                    capture="environment"
                    data-heic-aware
                    class="hidden"
                />
                <label for="receipt-camera"
                       class="mt-1 inline-flex cursor-pointer items-center gap-2 rounded-md border border-input bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <x-heroicon-o-camera class="h-5 w-5" />
                    Tomar foto
                </label>

                <button
                    type="button"
                    wire:click="analyze"
                    wire:loading.attr="disabled"
                    wire:target="analyze,receipt"
                    class="mt-1 inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                    <svg wire:loading wire:target="analyze" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span wire:loading.remove wire:target="analyze">📷 Analizar recibo</span>
                    <span wire:loading wire:target="analyze">Analizando…</span>
                </button>
                <span wire:loading wire:target="receipt" class="ml-2 text-xs text-blue-600">Subiendo imagen…</span>
            </div>

            {{-- Defaults globales --}}
            @if(! empty($rows))
                <div class="flex flex-wrap items-end gap-3 border-t border-border pt-4">
                    <div class="space-y-1">
                        <x-input-label for="defaultCategoryId" value="Categoría por defecto" />
                        <select id="defaultCategoryId" wire:model="defaultCategoryId" class="flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm">
                            <option value="">— elegir —</option>
                            @foreach($categories as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="space-y-1">
                        <x-input-label for="defaultUnitId" value="Unidad por defecto" />
                        <select id="defaultUnitId" wire:model="defaultUnitId" class="flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm">
                            <option value="">— elegir —</option>
                            @foreach($units as $u)
                                <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->symbol }})</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="button" wire:click="applyDefaultsToAll"
                        class="inline-flex items-center gap-1.5 rounded-md border border-input bg-background px-3 py-2 text-sm font-medium hover:bg-accent">
                        Aplicar a todas
                    </button>
                </div>

                {{-- Tabla editable --}}
                <div class="overflow-x-auto border border-border rounded-lg">
                    <table class="min-w-full divide-y divide-border text-sm">
                        <thead class="bg-muted/50">
                            <tr>
                                <th class="px-3 py-2 text-center w-12">Incluir</th>
                                <th class="px-3 py-2 text-left">Nombre</th>
                                <th class="px-3 py-2 text-right w-28">Precio compra</th>
                                <th class="px-3 py-2 text-center w-24">Stock</th>
                                <th class="px-3 py-2 text-left w-40">Categoría</th>
                                <th class="px-3 py-2 text-left w-40">Unidad</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach($rows as $i => $row)
                                <tr wire:key="row-{{ $i }}" @class(['opacity-50' => $row['exists']])>
                                    <td class="px-3 py-2 text-center">
                                        <input type="checkbox" wire:model="rows.{{ $i }}.include" class="h-4 w-4 rounded text-primary">
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="text" wire:model="rows.{{ $i }}.name" class="w-full rounded-md border border-input bg-background px-2 py-1 text-sm">
                                        @if($row['exists'])
                                            <span class="text-xs text-amber-600">ya existe en catálogo</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="number" step="0.01" min="0" wire:model="rows.{{ $i }}.purchase_price" class="w-24 text-right rounded-md border border-input bg-background px-2 py-1 text-sm">
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="number" min="0" wire:model="rows.{{ $i }}.quantity" class="w-20 text-center rounded-md border border-input bg-background px-2 py-1 text-sm">
                                    </td>
                                    <td class="px-3 py-2">
                                        <select wire:model="rows.{{ $i }}.category_id" class="w-full rounded-md border border-input bg-background px-2 py-1 text-sm">
                                            <option value="">—</option>
                                            @foreach($categories as $c)
                                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-3 py-2">
                                        <select wire:model="rows.{{ $i }}.unit_id" class="w-full rounded-md border border-input bg-background px-2 py-1 text-sm">
                                            <option value="">—</option>
                                            @foreach($units as $u)
                                                <option value="{{ $u->id }}">{{ $u->symbol }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex justify-end gap-3 border-t border-border pt-4">
                    <button type="button" x-on:click="$dispatch('close-modal', { name: 'receipt-import-modal' })"
                        class="inline-flex items-center rounded-md border border-input bg-background px-4 py-2 text-sm font-medium hover:bg-accent">
                        Cancelar
                    </button>
                    <button type="button" wire:click="import" wire:loading.attr="disabled" wire:target="import"
                        class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
                        <svg wire:loading wire:target="import" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        Crear productos
                    </button>
                </div>
            @endif
        </div>
    </x-modal>
</div>
