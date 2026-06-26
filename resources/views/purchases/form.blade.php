<div class="space-y-6">
    <!-- Sección de Cabecera de Entrada -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 bg-gray-50 p-4 rounded-lg border border-gray-200">
        <!-- Proveedor -->
        <div class="space-y-2">
            <x-input-label for="supplier_id" :value="__('Proveedor')" required />
            <div class="w-full">
                <select id="supplier_id" name="supplier_id"
                        x-init="initSupplierSelect($el)"
                        x-model="supplier_id"
                        autocomplete="off">
                    <option value=""></option>
                    @if(old('supplier_id'))
                        @php
                            $oldSupplier = \App\Models\Supplier::find(old('supplier_id'));
                        @endphp
                        @if($oldSupplier)
                            <option value="{{ $oldSupplier->id }}" selected>{{ $oldSupplier->name . ($oldSupplier->phone ? ' | ' . $oldSupplier->phone : '') }}</option>
                        @endif
                    @elseif(isset($purchase) && $purchase->supplier)
                        <option value="{{ $purchase->supplier_id }}" selected>{{ $purchase->supplier->name . ($purchase->supplier->phone ? ' | ' . $purchase->supplier->phone : '') }}</option>
                    @endif
                </select>
            </div>
            <x-input-error :messages="$errors->get('supplier_id')" />
        </div>

        <!-- Factura (Opcional) -->
        <div class="space-y-2">
            <x-input-label for="invoice_number" :value="__('Número de Factura (Opcional)')" />
            <x-text-input
                id="invoice_number"
                type="text"
                name="invoice_number"
                :value="old('invoice_number', $purchase->invoice_number ?? '')"
                placeholder="Dejar vacío para borradores"
                class="block w-full"
            />
            <x-input-error :messages="$errors->get('invoice_number')" />
        </div>

        <!-- Imagen de Comprobante -->
        <div class="space-y-2">
            <x-input-label for="proof_image" :value="__('Comprobante de Recibo')" />
            {{-- Input principal: galería/archivos (PC) + recibe la foto de cámara vía DataTransfer.
                 Es el que se envía con el form (name="proof_image") y el que lee analyzeReceipt(). --}}
            <input
                id="proof_image"
                type="file"
                name="proof_image"
                accept="image/*"
                data-heic-aware
                @change="onReceiptChange($event)"
                class="block w-full text-sm text-gray-500
                    file:mr-4 file:py-2 file:px-4
                    file:rounded-md file:border-0
                    file:text-sm file:font-semibold
                    file:bg-indigo-50 file:text-indigo-700
                    hover:file:bg-indigo-100"
            />
            <x-input-error :messages="$errors->get('proof_image')" />

            {{-- Input cámara: capture="environment" abre cámara trasera en móvil (foto única).
                 Al capturar, copia el archivo al #proof_image real para que valga tanto para
                 enviar el form como para el análisis IA. --}}
            <input
                id="proof_image_camera"
                type="file"
                accept="image/*"
                capture="environment"
                data-heic-aware
                class="hidden"
                @change="captureReceiptPhoto($event)"
            />

            <div class="mt-2 flex flex-wrap items-center gap-2">
                <label for="proof_image_camera"
                       class="inline-flex cursor-pointer items-center gap-2 rounded-md border border-input bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <x-heroicon-o-camera class="h-5 w-5" />
                    Tomar foto
                </label>

                <button
                    type="button"
                    @click="analyzeReceipt()"
                    :disabled="analyzing"
                    class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                    <svg x-show="analyzing" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span x-text="analyzing ? 'Analizando recibo…' : '📷 Analizar recibo con IA'"></span>
                </button>
            </div>

            <div class="mt-2">
                <template x-if="receiptPreviewUrl">
                    <img :src="receiptPreviewUrl" class="h-20 w-auto rounded border border-gray-200 object-cover">
                </template>
                @if(isset($purchase) && $purchase->proof_image)
                    <img x-show="!receiptPreviewUrl" src="{{ Storage::url($purchase->proof_image) }}" class="h-20 w-auto rounded border border-gray-200 object-cover">
                @endif
            </div>
        </div>

        <!-- Fechas -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4"><!-- responsive: fixed grid-cols-2 → mobile single col -->
            <div class="space-y-2">
                <x-input-label for="purchase_date" :value="__('Fecha de Compra')" required />
                <x-text-input
                    id="purchase_date"
                    type="date"
                    name="purchase_date"
                    :value="old('purchase_date', $purchase->purchase_date ? \Carbon\Carbon::parse($purchase->purchase_date)->format('Y-m-d') : date('Y-m-d'))"
                    class="block w-full"
                />
                <x-input-error :messages="$errors->get('purchase_date')" />
            </div>
            <div class="space-y-2">
                <x-input-label for="due_date" :value="__('Fecha de Vencimiento')" />
                <x-text-input
                    id="due_date"
                    type="date"
                    name="due_date"
                    :value="old('due_date', $purchase->due_date ? \Carbon\Carbon::parse($purchase->due_date)->format('Y-m-d') : '')"
                    class="block w-full"
                />
                <x-input-error :messages="$errors->get('due_date')" />
            </div>
        </div>

         <!-- Estado (Solo Lectura) -->
         <div class="space-y-2">
            <x-input-label :value="__('Estado')" />
            <div class="flex h-10 w-full items-center rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-500">
                {{ isset($purchase) && $purchase->status ? $purchase->status->label() : 'Borrador (Predeterminado)' }}
            </div>
        </div>

        <!-- Notas -->
        <div class="md:col-span-2 space-y-2">
            <x-input-label for="notes" :value="__('Notas')" />
            <textarea
                id="notes"
                name="notes"
                rows="2"
                class="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                placeholder="Notas adicionales..."
            >{{ old('notes', $purchase->notes ?? '') }}</textarea>
            <x-input-error :messages="$errors->get('notes')" />
        </div>
    </div>

    <!-- Sección de Productos -->
    <div class="space-y-4">
        <!-- Barra de Búsqueda -->
        <div class="relative z-20">
             <select
                id="master_product_search"
                x-init="initMasterSearch($el)"
                placeholder="Buscar Producto para Agregar..."
                autocomplete="off"
            ></select>
        </div>

        <!-- Tabla del Carrito -->
        <div class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden flex flex-col">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50 sticky top-0 z-10">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Producto</th>
                            <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Cant.</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Precio Compra</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Precio Venta</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Subtotal</th>
                            <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Acción</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <template x-for="(item, index) in items" :key="item.key">
                            <tr :class="index % 2 === 0 ? 'bg-white' : 'bg-gray-50'" class="hover:bg-indigo-50 transition-colors group">
                                <!-- Nombre del Producto -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900" x-text="item.product_name"></div>
                                    <div class="text-xs text-gray-500" x-text="item.product_code || 'ID: ' + item.product_id"></div>
                                    <input type="hidden" :name="`items[${index}][product_name]`" :value="item.product_name">
                                    <input type="hidden" :name="`items[${index}][product_id]`" :value="item.product_id">
                                    <input type="hidden" :name="`items[${index}][product_code]`" :value="item.product_code">
                                </td>

                                <!-- Cantidad -->
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <input
                                        type="number"
                                        :name="`items[${index}][quantity]`"
                                        x-model.number="item.quantity"
                                        @input="calculateLine(index)"
                                        class="w-20 text-center border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 text-sm shadow-sm"
                                        min="1"
                                        placeholder="1"
                                    >
                                    <p x-show="hasError(`items.${index}.quantity`)" x-text="getError(`items.${index}.quantity`)" class="text-xs text-red-600 mt-1"></p>
                                </td>

                                <!-- Precio Compra -->
                                <td class="px-6 py-4 whitespace-nowrap text-right" x-data="{
                                    display: '',
                                    init() {
                                        this.display = this.formatNumber(item.unit_price || 0);
                                        this.$watch('item.unit_price', value => this.display = this.formatNumber(value || 0));
                                    },
                                    update(e) {
                                        let raw = e.target.value;
                                        if(window.thousandSeparator) raw = raw.split(window.thousandSeparator).join('');
                                        if(window.decimalSeparator && window.decimalSeparator !== '.') raw = raw.replace(window.decimalSeparator, '.');
                                        raw = raw.replace(/[^0-9\.-]/g, '');

                                        // Input is Bs decimal, storage is cents (×100)
                                        let bs = raw ? parseFloat(raw) : 0;
                                        item.unit_price = isNaN(bs) ? 0 : Math.round(bs * 100);

                                        calculateLine(index);
                                    },
                                    formatNumber(value) {
                                        // value is cents; display as Bs decimal with separators
                                        let cents = parseInt(value) || 0;
                                        let isNegative = cents < 0;
                                        let bs = Math.abs(cents) / 100;

                                        let strAmount = bs.toFixed(2);
                                        let parts = strAmount.split('.');
                                        let integerPart = parts[0];
                                        let decimalPart = window.decimalSeparator + parts[1];

                                        let rgx = /(\d+)(\d{3})/;
                                        while (rgx.test(integerPart)) {
                                            integerPart = integerPart.replace(rgx, '$1' + window.thousandSeparator + '$2');
                                        }

                                        let num = integerPart + decimalPart;
                                        return isNegative ? '-' + num : num;
                                    }
                                }">
                                    <div class="relative rounded-md shadow-sm w-32 ml-auto">
                                        <div class="absolute inset-y-0 flex items-center pointer-events-none" :class="window.currencyPosition === 'left' ? 'left-0 pl-2' : 'right-0 pr-2'">
                                            <span class="text-gray-500 sm:text-xs" x-text="window.currencySymbol"></span>
                                        </div>
                                        <input
                                            type="text"
                                            x-model="display"
                                            @input="update($event)"
                                            class="focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md"
                                            :class="window.currencyPosition === 'left' ? 'pl-8 pr-2 text-right' : 'pr-8 pl-2 text-left'"
                                            placeholder="0"
                                        >
                                    </div>
                                    <input type="hidden" :name="`items[${index}][unit_price]`" :value="item.unit_price">
                                    <p x-show="hasError(`items.${index}.unit_price`)" x-text="getError(`items.${index}.unit_price`)" class="text-xs text-red-600 mt-1"></p>
                                </td>

                                <!-- Precio Venta -->
                                <td class="px-6 py-4 whitespace-nowrap text-right" x-data="{
                                    display: '',
                                    init() {
                                        this.display = this.formatNumber(item.selling_price || 0);
                                        this.$watch('item.selling_price', value => this.display = this.formatNumber(value || 0));
                                    },
                                    update(e) {
                                        let raw = e.target.value;
                                        if(window.thousandSeparator) raw = raw.split(window.thousandSeparator).join('');
                                        if(window.decimalSeparator && window.decimalSeparator !== '.') raw = raw.replace(window.decimalSeparator, '.');
                                        raw = raw.replace(/[^0-9\.-]/g, '');

                                        let bs = raw ? parseFloat(raw) : 0;
                                        item.selling_price = isNaN(bs) ? 0 : Math.round(bs * 100);
                                    },
                                    formatNumber(value) {
                                        let cents = parseInt(value) || 0;
                                        let isNegative = cents < 0;
                                        let bs = Math.abs(cents) / 100;

                                        let strAmount = bs.toFixed(2);
                                        let parts = strAmount.split('.');
                                        let integerPart = parts[0];
                                        let decimalPart = window.decimalSeparator + parts[1];

                                        let rgx = /(\d+)(\d{3})/;
                                        while (rgx.test(integerPart)) {
                                            integerPart = integerPart.replace(rgx, '$1' + window.thousandSeparator + '$2');
                                        }

                                        let num = integerPart + decimalPart;
                                        return isNegative ? '-' + num : num;
                                    }
                                }">
                                    <div class="relative rounded-md shadow-sm w-32 ml-auto">
                                        <div class="absolute inset-y-0 flex items-center pointer-events-none" :class="window.currencyPosition === 'left' ? 'left-0 pl-2' : 'right-0 pr-2'">
                                            <span class="text-gray-500 sm:text-xs" x-text="window.currencySymbol"></span>
                                        </div>
                                        <input
                                            type="text"
                                            x-model="display"
                                            @input="update($event)"
                                            class="focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md"
                                            :class="window.currencyPosition === 'left' ? 'pl-8 pr-2 text-right' : 'pr-8 pl-2 text-left'"
                                            placeholder="0"
                                        >
                                    </div>
                                    <input type="hidden" :name="`items[${index}][selling_price]`" :value="item.selling_price">

                                    <template x-if="(parseFloat(item.selling_price) || 0) < (parseFloat(item.unit_price) || 0) && (parseFloat(item.selling_price) || 0) > 0">
                                        <div class="text-xs text-amber-600 mt-1 flex items-center justify-end font-medium">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3 h-3 mr-1">
                                                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                                            </svg>
                                            Margen bajo
                                        </div>
                                    </template>
                                </td>

                                <!-- Subtotal -->
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-gray-900">
                                    <span x-text="window.formatMoney(item.subtotal)"></span>
                                </td>

                                <!-- Acción -->
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                    <button @click="removeItem(index)" type="button" class="flex items-center justify-center w-8 h-8 rounded-full bg-red-100 text-red-600 hover:bg-red-200 focus:outline-none transition-colors mx-auto">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                    </button>
                                </td>
                            </tr>
                        </template>
                        <template x-if="items.length === 0">
                            <tr>
                                <td colspan="6" class="px-6 py-20 text-center text-gray-500">
                                    <div class="flex flex-col items-center justify-center">
                                        <svg class="w-12 h-12 text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                                        <p class="text-base font-medium">No hay productos agregados</p>
                                        <p class="text-sm text-gray-400">Busque productos arriba para agregar a la lista de compra</p>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot class="bg-gray-50 border-t border-gray-200">
                        <tr>
                            <td colspan="4" class="px-6 py-4 text-right font-bold text-gray-900 text-base">Total de Compra:</td>
                            <td class="px-6 py-4 text-right font-bold text-blue-600 text-lg">
                                <span x-text="window.formatMoney(total)"></span>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Productos del recibo no reconocidos -->
    <template x-if="unmatchedItems.length > 0">
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4">
            <p class="text-sm font-semibold text-amber-800 mb-2">No reconocidos del recibo (búscalos manualmente arriba o ignóralos):</p>
            <ul class="space-y-1">
                <template x-for="(u, i) in unmatchedItems" :key="i">
                    <li class="flex items-center justify-between text-sm text-amber-900">
                        <span>
                            <span x-text="u.raw_name" class="font-medium"></span>
                            <span class="text-amber-700" x-text="' · cant: ' + u.quantity + ' · precio: ' + window.formatMoney(u.unit_price)"></span>
                        </span>
                        <button type="button" @click="unmatchedItems.splice(i, 1)" class="text-amber-600 hover:text-amber-800 text-xs">Quitar</button>
                    </li>
                </template>
            </ul>
        </div>
    </template>

    <!-- Acciones -->
    <div class="flex flex-wrap items-center justify-end gap-3 pt-6 border-t border-gray-200">
        <a href="{{ route('purchases.index') }}" class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 border border-input bg-background hover:bg-accent hover:text-accent-foreground h-10 px-4 py-2">
            {{ __('Cancelar') }}
        </a>

        <x-primary-button class="flex items-center gap-2" ::disabled="loading">
            <svg x-show="loading" class="animate-spin -ml-1 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span x-text="loading ? 'Procesando...' : ({{ isset($purchase->id) ? '`Actualizar Compra`' : '`Crear Compra`' }})"></span>
        </x-primary-button>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('purchaseForm', (initialData) => ({
            items: (initialData.items || []).map(i => ({
                ...i,
                key: i.key || Math.random().toString(36).substr(2, 9),
                subtotal: parseInt(i.subtotal) || 0
            })),
            supplier_id: initialData.supplier_id || '',
            status: initialData.status || 'draft',
            loading: false,
            analyzing: false,
            unmatchedItems: [],
            receiptPreviewUrl: '',
            errors: initialData.errors || {},

            init() {
                // Inicializar verificaciones u otra lógica si es necesario
            },

            hasError(field) {
                return !!this.errors[field];
            },

            getError(field) {
                return this.errors[field] ? this.errors[field][0] : '';
            },

            submitForm(e) {
                if (this.loading) return;
                this.loading = true;
                e.target.submit();
            },

            removeItem(index) {
                this.items.splice(index, 1);
            },

            calculateLine(index) {
                let item = this.items[index];
                let qty = parseInt(item.quantity);
                let price = parseInt(item.unit_price);

                // Asegurar que no sea NaN
                qty = isNaN(qty) ? 0 : qty;
                price = isNaN(price) ? 0 : price;

                item.subtotal = qty * price;
            },

            get total() {
                return this.items.reduce((sum, item) => {
                    let sub = parseInt(item.subtotal);
                    return sum + (isNaN(sub) ? 0 : sub);
                }, 0);
            },

            // Helper para TomSelect
            waitForTomSelect(callback) {
                if (window.TomSelect) {
                    callback();
                } else {
                    setTimeout(() => this.waitForTomSelect(callback), 50);
                }
            },

            initSupplierSelect(el) {
                let self = this;
                this.waitForTomSelect(() => {
                    new TomSelect(el, {
                        placeholder: 'Seleccionar Proveedor...',
                        preload: 'focus',
                        valueField: 'value',
                        labelField: 'text',
                        searchField: 'text',
                        onChange: function(value) {
                            self.supplier_id = value;
                        },
                        load: function(query, callback) {
                            var url = '{{ route("ajax.suppliers.search") }}';

                            fetch(url, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                                },
                                body: JSON.stringify({ q: query })
                            })
                                .then(response => response.json())
                                .then(json => {
                                    callback(json);
                                }).catch(() => {
                                    callback();
                                });
                        }
                    });
                });
            },

            // Callback para agregar producto desde la Búsqueda Maestra
            addProduct(product) {
                let existingIndex = this.items.findIndex(i => i.product_id == product.value);

                if (existingIndex !== -1) {
                    this.items[existingIndex].quantity += 1;
                    this.calculateLine(existingIndex);

                    window.dispatchEvent(new CustomEvent('toast', {
                        detail: {
                            message: 'El producto ya existe. Cantidad actualizada.',
                            type: 'info'
                        }
                    }));
                } else {
                    this.items.push({
                        key: Math.random().toString(36).substr(2, 9),
                        product_id: product.value,
                        product_name: product.text,
                        product_code: product.sku,
                        quantity: 1,
                        unit_price: product.price || 0,
                        selling_price: product.selling_price || 0,
                        subtotal: (product.price || 0) * 1
                    });

                    window.dispatchEvent(new CustomEvent('toast', {
                        detail: {
                            message: 'Producto "' + product.text + '" agregado a la lista.',
                            type: 'success'
                        }
                    }));
                }
            },

            // Copia la foto capturada por la cámara al input #proof_image real,
            // así sirve tanto para enviar el form como para analyzeReceipt().
            captureReceiptPhoto(event) {
                const file = event.target.files && event.target.files[0];
                if (!file) return;
                const target = document.getElementById('proof_image');
                try {
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    target.files = dt.files;
                } catch (e) {
                    // Navegador sin DataTransfer asignable: aviso para usar galería.
                    window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Tu navegador no permite cámara directa; usa el selector de archivo.', type: 'info' } }));
                    return;
                }
                target.dispatchEvent(new Event('change', { bubbles: true }));
            },

            // Actualiza el preview cuando cambia el archivo (galería o cámara).
            onReceiptChange(event) {
                const file = event.target.files && event.target.files[0];
                if (this.receiptPreviewUrl) {
                    URL.revokeObjectURL(this.receiptPreviewUrl);
                }
                this.receiptPreviewUrl = file ? URL.createObjectURL(file) : '';
            },

            async analyzeReceipt() {
                if (this.analyzing) return;
                const input = document.getElementById('proof_image');
                const file = input?.files?.[0];
                if (!file) {
                    window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Selecciona una imagen del recibo primero.', type: 'info' } }));
                    return;
                }

                this.analyzing = true;
                const fd = new FormData();
                fd.append('receipt', file);

                try {
                    const res = await fetch('{{ route("purchases.parse-receipt") }}', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                            // Sin Accept JSON, una validación fallida (imagen muy grande/mime)
                            // responde 302→HTML y res.json() revienta como "Error de red".
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: fd,
                    });

                    // Parseo robusto: el cuerpo puede no ser JSON (419/413/500/HTML).
                    const text = await res.text();
                    let data = {};
                    try { data = text ? JSON.parse(text) : {}; } catch (_) { data = {}; }

                    if (!res.ok) {
                        let msg = data.error || data.message;
                        if (data.errors) {
                            msg = Object.values(data.errors).flat().join(' ');
                        }
                        if (!msg) {
                            msg = (res.status === 413)
                                ? 'La imagen es muy grande. Usa una foto de menor resolución.'
                                : 'No se pudo leer el recibo (error ' + res.status + ').';
                        }
                        window.dispatchEvent(new CustomEvent('toast', { detail: { message: msg, type: 'error' } }));
                        return;
                    }

                    // Fecha
                    if (data.purchase_date) {
                        const dateEl = document.getElementById('purchase_date');
                        if (dateEl) dateEl.value = data.purchase_date;
                    }
                    // Proveedor (solo si casó uno existente)
                    if (data.supplier && data.supplier.id) {
                        this.supplier_id = String(data.supplier.id);
                    }
                    // Productos casados → tabla
                    (data.matched || []).forEach(m => {
                        this.addProduct({
                            value: m.product_id,
                            text: m.product_name,
                            sku: m.product_code,
                            price: m.unit_price,       // céntimos
                            selling_price: 0,
                        });
                        const idx = this.items.findIndex(i => i.product_id == m.product_id);
                        if (idx !== -1) {
                            this.items[idx].quantity = m.quantity;
                            this.items[idx].unit_price = m.unit_price;
                            this.calculateLine(idx);
                        }
                    });
                    // No reconocidos
                    this.unmatchedItems = data.unmatched || [];

                    const n = (data.matched || []).length;
                    window.dispatchEvent(new CustomEvent('toast', {
                        detail: { message: `${n} producto(s) reconocido(s). Revisa antes de guardar.`, type: 'success' }
                    }));
                } catch (e) {
                    window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Error de red al analizar el recibo.', type: 'error' } }));
                } finally {
                    this.analyzing = false;
                }
            },

            initMasterSearch(el) {
                let self = this;
                this.waitForTomSelect(() => {
                    let ts = new TomSelect(el, {
                        placeholder: 'Buscar Producto para Agregar...',
                        preload: 'focus',
                        valueField: 'value',
                        labelField: 'text',
                        searchField: 'text',
                        closeAfterSelect: false,
                        openOnFocus: true,
                        load: function(query, callback) {
                            var url = '{{ route("ajax.products.search") }}';

                            fetch(url, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                                },
                                body: JSON.stringify({ q: query })
                            })
                            .then(response => response.json())
                            .then(json => {
                                callback(json);
                            }).catch(() => {
                                callback();
                            });
                        },
                        onItemAdd: function(value, item) {
                            let data = this.options[value];
                            if (data) {
                                self.addProduct(data);
                            }
                            this.clear(true);
                            this.focus();
                        }
                    });
                });
            }
        }));
    });
</script>
@endpush