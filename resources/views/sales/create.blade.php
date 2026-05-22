<x-app-layout title="POS">
    <div class="mx-auto px-2 sm:px-4 lg:px-6 py-3"
        x-data="pos()"
        x-init="init()"
        @keydown.window.f1.prevent="$refs.searchInput && $refs.searchInput.focus()"
        @keydown.window.f2.prevent="customerTs && customerTs.focus()"
        @keydown.window.f3.prevent="openConfirmation()"
        @keydown.window.f4.prevent="openCustomerModal()">

        {{-- ============================================================
             LAYOUT: 1 columna en móvil, 2 columnas en lg.
             En móvil el panel de pago se convierte en drawer slide-up.
             ============================================================ --}}
        <div class="flex flex-col lg:grid lg:grid-cols-[1fr_380px] gap-4 lg:h-[calc(100vh-90px)]">

            {{-- ============ COLUMNA IZQUIERDA: catálogo + carrito ============ --}}
            <div class="flex flex-col gap-3 min-h-0">

                {{-- Buscador + toggle vista (lista/grid) --}}
                <div class="flex items-center gap-2">
                    <div class="relative flex-1">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <input
                            x-ref="searchInput"
                            type="text"
                            x-model.debounce.300ms="searchQuery"
                            @input="loadProducts(searchQuery)"
                            placeholder="Buscar producto por nombre o SKU [F1]…"
                            class="w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 shadow-sm">
                    </div>
                    <button @click="viewMode = viewMode === 'grid' ? 'list' : 'grid'"
                            class="hidden sm:flex items-center gap-1 px-3 py-2.5 border border-gray-300 rounded-lg text-sm hover:bg-gray-50 transition-colors shrink-0"
                            :title="viewMode === 'grid' ? 'Cambiar a vista lista' : 'Cambiar a vista cuadrícula'">
                        <template x-if="viewMode === 'grid'">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                        </template>
                        <template x-if="viewMode === 'list'">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h6v6H4V6zM14 6h6v6h-6V6zM4 14h6v6H4v-6zM14 14h6v6h-6v-6z"/></svg>
                        </template>
                    </button>
                </div>

                {{-- TomSelect oculto: lo mantenemos para que F1 + acceso por teclado fluya
                     igual que antes. El grid visual es el primary path. --}}
                <div class="hidden">
                    <select x-ref="productSelect" autocomplete="off"></select>
                </div>

                {{-- ============ Grid visual de productos ============ --}}
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden flex flex-col min-h-0">
                    <header class="px-3 py-2 border-b border-gray-100 flex items-center justify-between bg-gray-50">
                        <h3 class="text-xs font-semibold text-gray-600 uppercase tracking-wide">
                            <span x-show="searchQuery">Resultados (<span x-text="visibleProducts.length"></span>)</span>
                            <span x-show="!searchQuery">Productos disponibles</span>
                        </h3>
                        <span class="text-xs text-gray-400" x-show="loadingProducts">Cargando…</span>
                    </header>

                    {{-- Grid responsive: 2 cols móvil, 3 tablet, 4 desktop, 5 wide --}}
                    <div class="flex-1 overflow-y-auto p-2 min-h-[200px]"
                         x-show="viewMode === 'grid'">
                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5 gap-2"
                             x-show="visibleProducts.length > 0">
                            <template x-for="product in visibleProducts" :key="product.id">
                                <button @click="addToCart(product)"
                                        class="group relative bg-white border border-gray-200 rounded-lg overflow-hidden hover:border-indigo-400 hover:shadow-md transition-all text-left flex flex-col">

                                    {{-- Imagen --}}
                                    <div class="relative aspect-square bg-gray-50 overflow-hidden">
                                        <img :src="product.image_url"
                                             :alt="product.name"
                                             loading="lazy"
                                             class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-200"
                                             onerror="this.src='{{ asset('images/placeholder-product.svg') }}'">

                                        {{-- Featured badge --}}
                                        <template x-if="product.featured">
                                            <span class="absolute top-1 left-1 bg-amber-500 text-white text-[9px] font-bold px-1.5 py-0.5 rounded-full uppercase tracking-wider">★</span>
                                        </template>

                                        {{-- Stock badge --}}
                                        <span class="absolute top-1 right-1 text-[10px] font-bold px-1.5 py-0.5 rounded-full"
                                              :class="product.quantity > 10 ? 'bg-green-100 text-green-700' : product.quantity > 0 ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700'"
                                              x-text="product.quantity"></span>
                                    </div>

                                    {{-- Info --}}
                                    <div class="p-2 flex-1 flex flex-col">
                                        <p class="text-xs font-semibold text-gray-900 line-clamp-2 leading-tight"
                                           x-text="product.name"></p>
                                        <p class="text-[10px] text-gray-500 font-mono mt-0.5" x-text="product.sku"></p>
                                        <div class="mt-auto pt-1.5 flex items-center justify-between">
                                            <span class="text-sm font-bold text-indigo-600" x-text="formatCurrency(product.selling_price)"></span>
                                            <span class="text-[10px] text-gray-400" x-text="product.unit?.symbol || ''"></span>
                                        </div>
                                    </div>

                                    {{-- Hover overlay add icon --}}
                                    <div class="absolute inset-0 bg-indigo-600/0 group-hover:bg-indigo-600/10 transition-colors flex items-center justify-center pointer-events-none">
                                        <div class="opacity-0 group-hover:opacity-100 bg-indigo-600 text-white rounded-full w-10 h-10 flex items-center justify-center shadow-lg transform group-hover:scale-100 scale-75 transition-all">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/></svg>
                                        </div>
                                    </div>
                                </button>
                            </template>
                        </div>

                        <div x-show="visibleProducts.length === 0 && !loadingProducts" class="text-center py-12 text-gray-500">
                            <svg class="w-12 h-12 mx-auto text-gray-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <p class="text-sm">No se encontraron productos.</p>
                        </div>
                    </div>

                    {{-- Vista lista (modo compacto - desktop opcional) --}}
                    <div class="flex-1 overflow-y-auto"
                         x-show="viewMode === 'list'">
                        <div class="divide-y divide-gray-100">
                            <template x-for="product in visibleProducts" :key="'l-'+product.id">
                                <button @click="addToCart(product)"
                                        class="w-full flex items-center gap-3 px-3 py-2 hover:bg-indigo-50 transition-colors text-left">
                                    <img :src="product.image_url" :alt="product.name" class="w-10 h-10 object-cover rounded bg-gray-100 shrink-0"
                                         onerror="this.src='{{ asset('images/placeholder-product.svg') }}'">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-gray-900 truncate" x-text="product.name"></p>
                                        <p class="text-xs text-gray-500 font-mono" x-text="product.sku"></p>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <p class="text-sm font-bold text-indigo-600" x-text="formatCurrency(product.selling_price)"></p>
                                        <p class="text-xs"
                                           :class="product.quantity > 0 ? 'text-green-600' : 'text-red-600'"
                                           x-text="'Stock: ' + product.quantity"></p>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>

                {{-- ============ Carrito compacto: en desktop tabla, en móvil oculto (drawer) ============ --}}
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden hidden lg:flex flex-col"
                     style="max-height: 32vh;">
                    <header class="px-3 py-2 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                        <h3 class="text-xs font-semibold text-gray-600 uppercase tracking-wide">
                            Carrito (<span x-text="cart.length"></span>)
                        </h3>
                    </header>
                    <div class="overflow-y-auto flex-1">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50/50 sticky top-0 z-10">
                                <tr class="text-xs uppercase text-gray-500">
                                    <th class="px-3 py-2 text-left">Producto</th>
                                    <th class="px-2 py-2 text-right">Precio</th>
                                    <th class="px-2 py-2 text-center">Cant.</th>
                                    <th class="px-2 py-2 text-right">Desc.</th>
                                    <th class="px-2 py-2 text-right">Total</th>
                                    <th class="px-2 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <template x-for="(item, index) in cart" :key="item.id">
                                    <tr class="hover:bg-indigo-50/50">
                                        <td class="px-3 py-2">
                                            <div class="font-medium text-gray-900 text-sm" x-text="item.name"></div>
                                            <div class="text-[10px] text-gray-500 font-mono" x-text="item.sku"></div>
                                        </td>
                                        <td class="px-2 py-2 text-right text-xs text-gray-600" x-text="formatCurrency(item.price)"></td>
                                        <td class="px-2 py-2 text-center">
                                            <div class="inline-flex items-center gap-1">
                                                <button @click="item.quantity > 1 && item.quantity--; validateQty(index)"
                                                        class="w-6 h-6 rounded bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs">−</button>
                                                <input type="number" x-model.number="item.quantity" min="1" :max="item.max_stock"
                                                       @input="validateQty(index)"
                                                       class="w-12 text-center text-sm border-gray-300 rounded">
                                                <button @click="item.quantity < item.max_stock && item.quantity++; validateQty(index)"
                                                        class="w-6 h-6 rounded bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs">+</button>
                                            </div>
                                        </td>
                                        <td class="px-2 py-2 text-right">
                                            <input type="text" :value="formatNumber(item.discount)"
                                                   @input="item.discount = unformatNumber($event.target.value)"
                                                   class="w-20 text-right text-xs border-gray-300 rounded" placeholder="0">
                                        </td>
                                        <td class="px-2 py-2 text-right font-semibold text-sm" x-text="formatCurrency((item.price - item.discount) * item.quantity)"></td>
                                        <td class="px-2 py-2 text-center">
                                            <button @click="removeFromCart(index)" class="w-7 h-7 rounded-full bg-red-50 text-red-600 hover:bg-red-100">
                                                <svg class="w-3 h-3 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </td>
                                    </tr>
                                </template>
                                <template x-if="cart.length === 0">
                                    <tr>
                                        <td colspan="6" class="px-3 py-8 text-center text-gray-400 text-sm">
                                            Carrito vacío. Toca un producto arriba.
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- ============ COLUMNA DERECHA: Payment panel (drawer en móvil) ============ --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 flex flex-col overflow-hidden"
                 :class="cartDrawerOpen ? 'fixed inset-x-2 bottom-2 top-12 z-40 lg:static lg:inset-auto' : 'hidden lg:flex'">

                <header class="p-3 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
                    <h2 class="text-xs font-bold text-gray-600 uppercase tracking-wide">Detalles del pago</h2>
                    <button @click="cartDrawerOpen = false" class="lg:hidden w-8 h-8 rounded-full hover:bg-gray-200 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </header>

                <div class="p-3 space-y-4 flex-1 overflow-y-auto">

                    {{-- Mobile: cart items dentro del drawer --}}
                    <div class="lg:hidden">
                        <h3 class="text-xs font-semibold text-gray-500 uppercase mb-2">Carrito (<span x-text="cart.length"></span>)</h3>
                        <div class="space-y-2">
                            <template x-for="(item, index) in cart" :key="'m-'+item.id">
                                <div class="flex gap-2 items-center bg-gray-50 rounded-lg p-2">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs font-medium text-gray-900 truncate" x-text="item.name"></p>
                                        <p class="text-[10px] text-gray-500" x-text="formatCurrency(item.price) + ' c/u'"></p>
                                    </div>
                                    <div class="inline-flex items-center gap-1 shrink-0">
                                        <button @click="item.quantity > 1 && item.quantity--; validateQty(index)" class="w-7 h-7 rounded-full bg-white border border-gray-300 text-gray-700 text-sm">−</button>
                                        <span class="w-6 text-center text-sm font-medium" x-text="item.quantity"></span>
                                        <button @click="item.quantity < item.max_stock && item.quantity++; validateQty(index)" class="w-7 h-7 rounded-full bg-white border border-gray-300 text-gray-700 text-sm">+</button>
                                    </div>
                                    <button @click="removeFromCart(index)" class="w-7 h-7 rounded-full bg-red-50 text-red-600 shrink-0">
                                        <svg class="w-3 h-3 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                            </template>
                            <p x-show="cart.length === 0" class="text-center text-xs text-gray-400 py-4">Carrito vacío</p>
                        </div>
                    </div>

                    {{-- Customer --}}
                    <div class="bg-indigo-50 rounded-lg p-3 border border-indigo-100">
                        <div class="flex justify-between items-start mb-2">
                            <span class="text-[10px] font-bold text-indigo-500 uppercase">Cliente</span>
                            <button @click="openCustomerModal()" class="text-[10px] font-semibold text-indigo-600 border border-indigo-200 bg-white px-2 py-1 rounded">
                                + Nuevo (F4)
                            </button>
                        </div>
                        <template x-if="selectedCustomer">
                            <div class="flex justify-between items-center">
                                <div class="min-w-0">
                                    <h3 class="font-bold text-sm text-gray-900 truncate" x-text="selectedCustomer.name"></h3>
                                    <p class="text-xs text-gray-600 truncate" x-text="selectedCustomer.phone || 'Sin teléfono'"></p>
                                </div>
                                <button @click="resetCustomer()" class="text-gray-400 hover:text-red-500 shrink-0 ml-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                        </template>
                        <div x-show="!selectedCustomer">
                            <select x-ref="customerSelect" placeholder="Buscar cliente [F2]…" autocomplete="off"></select>
                        </div>
                    </div>

                    {{-- Totals --}}
                    <div class="space-y-2">
                        <div class="flex justify-between text-xs text-gray-600">
                            <span>Subtotal</span>
                            <span x-text="formatCurrency(subtotal)"></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-gray-500">Desc. global</span>
                            <div class="relative w-28">
                                <span class="absolute left-2 top-1/2 -translate-y-1/2 text-gray-400 text-xs" x-text="window.currencySymbol"></span>
                                <input type="text" :value="formatNumber(globalDiscount)"
                                       @input="globalDiscount = unformatNumber($event.target.value)"
                                       class="block w-full pl-7 pr-2 py-1 text-xs text-right border-gray-300 rounded" placeholder="0">
                            </div>
                        </div>
                        <div class="flex justify-between text-xs text-red-500" x-show="totalDiscount > 0">
                            <span>Descuento ítems</span>
                            <span x-text="'-' + formatCurrency(totalDiscount)"></span>
                        </div>
                        <div class="flex justify-between items-center pt-2 border-t border-gray-100">
                            <span class="text-sm font-bold text-gray-800">TOTAL</span>
                            <span class="text-xl font-extrabold text-blue-600" x-text="formatCurrency(total)"></span>
                        </div>
                    </div>

                    {{-- Payment --}}
                    <div class="space-y-3 pt-3 border-t border-gray-200">
                        <div>
                            <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1.5">Forma de pago</label>
                            <div class="grid grid-cols-2 gap-2">
                                <button @click="payment.method = 'cash'"
                                        class="px-3 py-2 text-xs font-medium rounded-md border"
                                        :class="payment.method === 'cash' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'">EFECTIVO</button>
                                <button @click="payment.method = 'transfer'"
                                        class="px-3 py-2 text-xs font-medium rounded-md border"
                                        :class="payment.method === 'transfer' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'">TRANSFERENCIA</button>
                            </div>
                        </div>

                        <template x-if="payment.method === 'cash'">
                            <div>
                                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Efectivo recibido</label>
                                <div class="relative">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 font-bold text-gray-500" x-text="window.currencySymbol"></span>
                                    <input type="text" :value="formatNumber(payment.cash_received)"
                                           @input="payment.cash_received = unformatNumber($event.target.value)"
                                           class="block w-full py-2.5 pl-10 pr-2 text-lg font-bold text-right border-gray-300 rounded-md focus:ring-indigo-500" placeholder="0">
                                </div>

                                <div class="p-2 rounded mt-2 flex justify-between items-center text-xs font-medium"
                                     :class="change < 0 ? 'bg-red-50 text-red-800' : 'bg-green-50 text-green-800'">
                                    <span class="uppercase" x-text="change < 0 ? 'Por pagar' : 'Cambio'"></span>
                                    <span class="text-base font-bold"
                                          :class="change < 0 ? 'text-red-700' : 'text-green-700'"
                                          x-text="formatCurrency(Math.abs(change))"></span>
                                </div>
                            </div>
                        </template>

                        <textarea x-model="payment.notes" rows="2"
                                  class="block w-full text-xs border-gray-300 rounded-md placeholder-gray-400 py-1.5"
                                  placeholder="Notas / Dirección…"></textarea>
                    </div>
                </div>

                {{-- Footer actions --}}
                <div class="p-3 border-t border-gray-200 bg-gray-50 flex gap-2">
                    <button @click="$dispatch('open-modal', { name: 'cancel-modal' })"
                            class="w-1/3 py-2.5 text-xs font-bold text-red-600 bg-white border border-red-200 rounded-lg hover:bg-red-600 hover:text-white transition-colors">
                        CANCELAR
                    </button>
                    <button @click="openConfirmation()"
                            :disabled="isSubmitting || cart.length === 0"
                            class="w-2/3 py-2.5 text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                        <template x-if="isSubmitting">
                            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        </template>
                        <span x-text="isSubmitting ? 'Procesando…' : 'PAGAR (F3)'"></span>
                    </button>
                </div>
            </div>
        </div>

        {{-- ============ Mobile floating cart button ============ --}}
        <button @click="cartDrawerOpen = true"
                x-show="cart.length > 0 && !cartDrawerOpen"
                class="lg:hidden fixed bottom-4 right-4 z-30 bg-blue-600 text-white rounded-full shadow-2xl px-4 py-3 flex items-center gap-2 hover:bg-blue-700 transition-colors">
            <div class="relative">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                <span class="absolute -top-1.5 -right-1.5 bg-red-500 text-white text-[10px] font-bold rounded-full w-4 h-4 flex items-center justify-center" x-text="cart.length"></span>
            </div>
            <span class="text-sm font-bold" x-text="formatCurrency(total)"></span>
        </button>

        {{-- Backdrop drawer móvil --}}
        <div x-show="cartDrawerOpen" x-cloak
             @click="cartDrawerOpen = false"
             class="lg:hidden fixed inset-0 bg-black/40 z-30"
             x-transition.opacity></div>

        {{-- Alpine component logic --}}
        <script>
            function pos() {
                return {
                    cart: [],
                    selectedCustomer: null,
                    payment: { method: 'cash', cash_received: 0, notes: '' },
                    globalDiscount: 0,
                    saleStatus: 'completed',
                    isSubmitting: false,

                    // Visual catalog
                    products: [],          // last fetched batch
                    visibleProducts: [],   // currently shown (after search filter)
                    loadingProducts: false,
                    searchQuery: '',
                    viewMode: 'grid',      // 'grid' | 'list'
                    cartDrawerOpen: false, // mobile drawer

                    // TomSelect instances (still kept for keyboard customer + bot compat)
                    productTs: null,
                    customerTs: null,

                    lastSearchQuery: '',

                    init() {
                        const savedCart = localStorage.getItem('pos_cart');
                        if (savedCart) this.cart = JSON.parse(savedCart);
                        const savedCustomer = localStorage.getItem('pos_customer');
                        if (savedCustomer) this.selectedCustomer = JSON.parse(savedCustomer);
                        const savedPayment = localStorage.getItem('pos_payment');
                        if (savedPayment) this.payment = JSON.parse(savedPayment);
                        const savedGlobalDiscount = localStorage.getItem('pos_globalDiscount');
                        if (savedGlobalDiscount) this.globalDiscount = parseInt(savedGlobalDiscount);
                        const savedViewMode = localStorage.getItem('pos_view_mode');
                        if (savedViewMode) this.viewMode = savedViewMode;

                        this.$watch('cart', (val) => localStorage.setItem('pos_cart', JSON.stringify(val)));
                        this.$watch('selectedCustomer', (val) => localStorage.setItem('pos_customer', JSON.stringify(val)));
                        this.$watch('payment', (val) => localStorage.setItem('pos_payment', JSON.stringify(val)));
                        this.$watch('globalDiscount', (val) => localStorage.setItem('pos_globalDiscount', val));
                        this.$watch('viewMode', (val) => localStorage.setItem('pos_view_mode', val));

                        // Initial product load (sin query muestra los primeros 40 alphabetical / featured)
                        this.loadProducts('');

                        // TomSelect oculto para parity y por si lo necesitamos como backup
                        this.initProductSelect();
                        this.initCustomerSelect();
                    },

                    async loadProducts(query) {
                        this.loadingProducts = true;
                        try {
                            const res = await fetch('{{ route("ajax.products.search") }}', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                },
                                body: JSON.stringify({ q: query, limit: 60 }),
                            });
                            const data = await res.json();
                            this.products = data;
                            this.visibleProducts = data;
                        } catch (e) {
                            console.error('loadProducts error', e);
                        } finally {
                            this.loadingProducts = false;
                        }
                    },

                    initProductSelect() {
                        if (!this.$refs.productSelect) return;
                        if (this.productTs) { this.productTs.destroy(); this.productTs = null; }
                        // TomSelect oculto se inicializa pero no se usa visualmente.
                        // Mantenido como hook para shortcuts F1 si quieres re-habilitar en futuro.
                    },

                    initCustomerSelect() {
                        if (!this.$refs.customerSelect) return;
                        if (this.customerTs) { this.customerTs.destroy(); this.customerTs = null; }

                        this.customerTs = new TomSelect(this.$refs.customerSelect, {
                            valueField: 'value',
                            labelField: 'text',
                            searchField: 'text',
                            preload: 'focus',
                            openOnFocus: true,
                            load: (query, callback) => {
                                fetch('{{ route("ajax.customers.search") }}', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                    },
                                    body: JSON.stringify({ q: query }),
                                })
                                .then(r => r.json()).then(callback).catch(() => callback());
                            },
                            render: {
                                option: (item, escape) => `
                                    <div class="py-2 px-3 hover:bg-indigo-50">
                                        <div class="font-medium text-gray-900">${escape(item.name)}</div>
                                        <div class="text-xs text-gray-500">${escape(item.phone || 'Sin teléfono')}</div>
                                    </div>`,
                            },
                            onChange: (value) => {
                                if (value) {
                                    const item = this.customerTs.options[value];
                                    if (item) {
                                        this.selectedCustomer = {
                                            id: item.value, name: item.name,
                                            phone: item.phone, email: item.email || '', address: item.address || '',
                                        };
                                        this.customerTs.clear();
                                    }
                                }
                            }
                        });
                    },

                    resetCustomer() {
                        this.selectedCustomer = null;
                        this.$nextTick(() => this.customerTs && this.customerTs.focus());
                    },

                    clearStorage() {
                        ['pos_cart', 'pos_customer', 'pos_payment', 'pos_globalDiscount'].forEach(k => localStorage.removeItem(k));
                    },

                    addToCart(product) {
                        const existing = this.cart.find(item => item.id === product.id);
                        if (existing) {
                            if (existing.quantity < product.quantity) {
                                existing.quantity++;
                                this.$dispatch('toast', { message: 'Cantidad actualizada', type: 'info' });
                            } else {
                                this.$dispatch('toast', { message: '¡Stock insuficiente!', type: 'error' });
                            }
                        } else {
                            if (product.quantity > 0) {
                                this.cart.push({
                                    id: product.id, name: product.name, sku: product.sku,
                                    price: product.selling_price, quantity: 1, max_stock: product.quantity,
                                    unit: product.unit ? product.unit.symbol : '', discount: 0,
                                });
                                this.$dispatch('toast', { message: '✓ ' + product.name, type: 'success' });
                            } else {
                                this.$dispatch('toast', { message: '¡Agotado!', type: 'error' });
                            }
                        }
                    },

                    validateQty(index) {
                        const item = this.cart[index];
                        if (item.quantity > item.max_stock) {
                            item.quantity = item.max_stock;
                            this.$dispatch('toast', { message: 'Máximo de stock alcanzado', type: 'warning' });
                        }
                        if (item.quantity < 1) item.quantity = 1;
                    },

                    removeFromCart(index) {
                        const removed = this.cart[index];
                        this.cart.splice(index, 1);
                        this.$dispatch('toast', { message: 'Eliminado: ' + removed.name, type: 'info' });
                    },

                    openCustomerModal() {
                        this.$dispatch('open-modal', { name: 'customer-modal' });
                        this.$nextTick(() => setTimeout(() => this.$refs.nameInput && this.$refs.nameInput.focus(), 100));
                    },

                    get subtotal() { return this.cart.reduce((s, i) => s + (i.price * i.quantity), 0); },
                    get totalDiscount() { return this.cart.reduce((s, i) => s + (i.discount * i.quantity), 0); },
                    get total() { return this.subtotal - this.totalDiscount - this.globalDiscount; },
                    get change() { return this.payment.method !== 'cash' ? 0 : this.payment.cash_received - this.total; },

                    formatCurrency(value) { return window.formatMoney(value); },
                    unformatNumber(value) {
                        if (typeof value !== 'string') return value || 0;
                        let raw = value;
                        if (window.thousandSeparator) raw = raw.split(window.thousandSeparator).join('');
                        if (window.decimalSeparator && window.decimalSeparator !== '.') raw = raw.replace(window.decimalSeparator, '.');
                        raw = raw.replace(/[^0-9\.-]/g, '');
                        if (raw === '' || raw === '-') return 0;
                        return Math.round((parseFloat(raw) || 0) * 100);
                    },
                    formatNumber(value) {
                        let cents = parseInt(value) || 0;
                        let isNeg = cents < 0;
                        let bs = Math.abs(cents) / 100;
                        let parts = bs.toFixed(2).split('.');
                        let intPart = parts[0];
                        let decPart = window.decimalSeparator + parts[1];
                        let rgx = /(\d+)(\d{3})/;
                        while (rgx.test(intPart)) intPart = intPart.replace(rgx, '$1' + window.thousandSeparator + '$2');
                        return (isNeg ? '-' : '') + intPart + decPart;
                    },

                    openConfirmation() {
                        if (this.cart.length === 0) return;
                        if (this.payment.method === 'cash' && this.payment.cash_received < this.total) {
                            this.$dispatch('toast', { message: '¡Pago insuficiente!', type: 'error' });
                            return;
                        }
                        this.$dispatch('open-modal', { name: 'confirmation-modal' });
                    },

                    async submitSale() {
                        this.isSubmitting = true;
                        try {
                            const items = this.cart.map(item => ({
                                product_id: item.id, quantity: item.quantity,
                                unit_price: item.price, discount: item.discount,
                            }));
                            const payload = {
                                customer_id: this.selectedCustomer?.id,
                                items, payment_method: this.payment.method,
                                cash_received: this.payment.cash_received,
                                notes: this.payment.notes,
                                global_discount: this.globalDiscount,
                                status: this.saleStatus,
                                sale_date: new Date().toISOString().slice(0, 10),
                                _token: '{{ csrf_token() }}',
                            };
                            const res = await fetch('{{ route("sales.store") }}', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                },
                                body: JSON.stringify(payload),
                            });
                            const data = await res.json();
                            if (res.ok && data.success) {
                                this.$dispatch('close-modal', { name: 'confirmation-modal' });
                                if (data.print_url) window.open(data.print_url, '_blank');
                                this.clearStorage();
                                this.resetForm();
                                this.cartDrawerOpen = false;
                                this.$dispatch('toast', { message: '¡Transacción exitosa!', type: 'success' });
                            } else {
                                this.$dispatch('toast', { message: data.message || 'Ocurrió un error', type: 'error' });
                            }
                        } catch (e) {
                            console.error(e);
                            this.$dispatch('toast', { message: 'Error de red', type: 'error' });
                        } finally {
                            this.isSubmitting = false;
                        }
                    },

                    resetForm() {
                        this.cart = [];
                        this.selectedCustomer = null;
                        this.payment = { method: 'cash', cash_received: 0, notes: '' };
                        this.globalDiscount = 0;
                        this.productTs && this.productTs.clear();
                        this.customerTs && this.customerTs.clear();
                    }
                }
            }
        </script>

        {{-- ============ Modals (sin cambios funcionales) ============ --}}
        <x-modal name="confirmation-modal" focusable>
            <div class="p-6">
                <div class="mb-6 space-y-1.5 text-center sm:text-left border-b border-gray-200 pb-4">
                    <h3 class="text-lg font-semibold leading-none tracking-tight">Confirmación del pago</h3>
                    <p class="text-sm text-muted-foreground">Por favor revise los detalles antes de procesar.</p>
                </div>
                <div class="grid gap-4 py-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-gray-500">Total Artículos</span>
                        <span class="font-semibold" x-text="cart.reduce((sum, i) => sum + parseInt(i.quantity), 0)"></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-gray-500">Subtotal</span>
                        <span class="font-semibold" x-text="formatCurrency(subtotal)"></span>
                    </div>
                    <div class="flex items-center justify-between text-red-600" x-show="totalDiscount > 0">
                        <span class="text-sm font-medium">Descuento</span>
                        <span class="font-semibold" x-text="'- ' + formatCurrency(totalDiscount)"></span>
                    </div>
                    <div class="flex items-center justify-between text-red-600" x-show="globalDiscount > 0">
                        <span class="text-sm font-medium">Descuento global</span>
                        <span class="font-semibold" x-text="'- ' + formatCurrency(globalDiscount)"></span>
                    </div>
                    <div class="flex items-center justify-between border-t border-gray-100 pt-2 mt-2">
                        <span class="text-lg font-bold">Total a pagar</span>
                        <span class="text-lg font-bold text-blue-600" x-text="formatCurrency(total)"></span>
                    </div>
                    <div class="flex items-center justify-between border-t border-gray-100 pt-2 mt-2" x-show="payment.method === 'cash'">
                        <span class="text-sm font-medium text-gray-500">Efectivo recibido</span>
                        <span class="font-semibold" x-text="formatCurrency(payment.cash_received)"></span>
                    </div>
                    <div class="flex items-center justify-between" x-show="payment.method === 'cash'">
                        <span class="text-sm font-medium text-gray-500">Cambio</span>
                        <span class="font-bold text-green-600" x-text="formatCurrency(change)"></span>
                    </div>
                </div>
                <div class="mt-6 border-t border-gray-200 pt-4 space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Estado de la venta</label>
                        <div class="grid grid-cols-2 gap-2">
                            <button @click="saleStatus = 'completed'"
                                    class="px-4 py-2 text-sm font-medium rounded-md border"
                                    :class="saleStatus === 'completed' ? 'bg-green-600 text-white border-green-600' : 'bg-white text-gray-700 border-gray-300'">COMPLETADO</button>
                            <button @click="saleStatus = 'pending'"
                                    class="px-4 py-2 text-sm font-medium rounded-md border"
                                    :class="saleStatus === 'pending' ? 'bg-yellow-500 text-white border-yellow-500' : 'bg-white text-gray-700 border-gray-300'">RESERVAR</button>
                        </div>
                    </div>
                    <button @click="submitSale()" :disabled="isSubmitting"
                            class="w-full flex justify-center items-center py-3 rounded-lg text-lg font-bold text-white disabled:opacity-50"
                            :class="saleStatus === 'completed' ? 'bg-blue-600 hover:bg-blue-700' : 'bg-gray-600 hover:bg-gray-700'">
                        <template x-if="isSubmitting">
                            <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        </template>
                        <span x-text="isSubmitting ? 'Procesando…' : 'PROCESAR VENTA'"></span>
                    </button>
                    <x-secondary-button type="button" @click="$dispatch('close-modal', { name: 'confirmation-modal' })" class="w-full justify-center">
                        Atrás
                    </x-secondary-button>
                </div>
            </div>
        </x-modal>

        {{-- Customer create modal (unchanged) --}}
        <x-modal name="customer-modal" focusable>
            <div class="p-6" x-data="{
                newCust: { name: '', email: '', phone: '', address: '', notes: '' },
                errors: {}, loading: false,
                async save() {
                    this.errors = {};
                    if (!this.newCust.name.trim()) { this.errors.name = 'El nombre es obligatorio.'; return; }
                    this.loading = true;
                    try {
                        const res = await fetch('{{ route('ajax.customers.store') }}', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                            body: JSON.stringify(this.newCust),
                        });
                        const data = await res.json();
                        if (res.ok) {
                            this.$dispatch('close-modal', { name: 'customer-modal' });
                            this.$dispatch('customer-created', data);
                            this.newCust = { name: '', email: '', phone: '', address: '', notes: '' };
                        } else if (data.errors) {
                            Object.keys(data.errors).forEach(k => this.errors[k] = data.errors[k][0]);
                        } else {
                            this.$dispatch('toast', { message: data.message || 'Error', type: 'error' });
                        }
                    } catch(e) { console.error(e); } finally { this.loading = false; }
                }
            }">
                <div class="mb-6 space-y-1.5 border-b border-gray-200 pb-4">
                    <h3 class="text-lg font-semibold">{{ __('Crear nuevo cliente') }}</h3>
                    <p class="text-sm text-muted-foreground">{{ __('Agregue un nuevo cliente a sus registros.') }}</p>
                </div>
                <div class="space-y-4">
                    <div>
                        <x-form-input name="new_name" label="Nombre completo" x-model="newCust.name" x-ref="nameInput" required />
                        <p x-show="errors.name" x-text="errors.name" class="text-sm text-red-600 mt-1" style="display:none"></p>
                    </div>
                    <div class="flex flex-col sm:flex-row gap-4">
                        <div class="w-full sm:w-1/2">
                            <x-form-input name="new_email" label="Email" type="email" x-model="newCust.email" />
                            <p x-show="errors.email" x-text="errors.email" class="text-sm text-red-600 mt-1" style="display:none"></p>
                        </div>
                        <div class="w-full sm:w-1/2">
                            <x-form-input name="new_phone" label="Teléfono" x-model="newCust.phone" />
                            <p x-show="errors.phone" x-text="errors.phone" class="text-sm text-red-600 mt-1" style="display:none"></p>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <x-input-label for="new_address" :value="__('Dirección')" />
                        <textarea id="new_address" x-model="newCust.address" rows="2" class="block w-full rounded-md border-gray-300 sm:text-sm" placeholder="Dirección completa"></textarea>
                    </div>
                    <div class="space-y-2">
                        <x-input-label for="new_notes" :value="__('Notas')" />
                        <textarea id="new_notes" x-model="newCust.notes" rows="2" class="block w-full rounded-md border-gray-300 sm:text-sm"></textarea>
                    </div>
                    <div class="mt-6 flex justify-end gap-3 border-t border-gray-200 pt-4">
                        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', { name: 'customer-modal' })">{{ __('Cancelar') }}</x-secondary-button>
                        <x-primary-button type="button" @click="save()" x-bind:disabled="loading">
                            <template x-if="loading"><svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg></template>
                            <span x-text="loading ? 'Guardando…' : 'Guardar cliente'"></span>
                        </x-primary-button>
                    </div>
                </div>
            </div>
        </x-modal>

        {{-- Cancel modal --}}
        <x-modal name="cancel-modal" focusable>
            <div class="bg-white px-4 pb-4 pt-5 sm:p-6 sm:pb-4">
                <div class="sm:flex sm:items-start">
                    <div class="mx-auto flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10">
                        <svg class="h-6 w-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                    </div>
                    <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                        <h3 class="text-lg font-semibold text-gray-900">¿Cancelar transacción?</h3>
                        <p class="text-sm text-gray-500 mt-2">Se perderán todos los artículos y selecciones actuales.</p>
                    </div>
                </div>
            </div>
            <div class="bg-gray-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6 gap-2">
                <x-danger-button @click="resetForm(); clearStorage(); $dispatch('close-modal', { name: 'cancel-modal' }); $dispatch('toast', { message: 'Transacción cancelada', type: 'info' })" class="w-full sm:w-auto justify-center">
                    Sí, cancelar
                </x-danger-button>
                <button type="button" @click="$dispatch('close-modal', { name: 'cancel-modal' })"
                        class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto">
                    No, regresar
                </button>
            </div>
        </x-modal>

        {{-- Customer-created listener --}}
        <div @customer-created.window="selectedCustomer = $event.detail;"></div>
    </div>
</x-app-layout>
