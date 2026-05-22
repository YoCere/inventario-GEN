<x-app-layout title="POS">
    <div class="mx-auto px-2 sm:px-4 lg:px-6 py-3"
        x-data="pos()"
        x-init="init()"
        @keydown.window.f1.prevent="$refs.searchInput && $refs.searchInput.focus()"
        @keydown.window.f3.prevent="openPaymentSheet()"
        @keydown.window.escape="paymentSheetOpen = false; cartDrawerOpen = false">

        {{-- ============================================================
             LAYOUT: catálogo izquierda + carrito sidebar derecha.
             Pago = modal/sheet al final (no compite con catálogo).
             Móvil: carrito drawer slide-up via floating button.
             ============================================================ --}}
        <div class="flex flex-col lg:grid lg:grid-cols-[1fr_340px] gap-4 lg:h-[calc(100vh-90px)]">

            {{-- ============ COLUMNA IZQUIERDA: catálogo ============ --}}
            <div class="flex flex-col gap-3 min-h-0">

                {{-- Buscador --}}
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
                            class="hidden sm:flex items-center justify-center w-11 h-11 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors shrink-0"
                            :title="viewMode === 'grid' ? 'Vista lista' : 'Vista cuadrícula'">
                        <template x-if="viewMode === 'grid'">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                        </template>
                        <template x-if="viewMode === 'list'">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h6v6H4V6zM14 6h6v6h-6V6zM4 14h6v6H4v-6zM14 14h6v6h-6v-6z"/></svg>
                        </template>
                    </button>
                </div>

                {{-- TomSelect oculto (mantener para compat / shortcut futuro) --}}
                <div class="hidden">
                    <select x-ref="productSelect" autocomplete="off"></select>
                </div>

                {{-- ============ Grid productos (ocupa 100% del espacio izquierdo) ============ --}}
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden flex flex-col min-h-0 flex-1">
                    <header class="px-3 py-2 border-b border-gray-100 flex items-center justify-between bg-gray-50">
                        <h3 class="text-xs font-semibold text-gray-600 uppercase tracking-wide">
                            <span x-show="searchQuery">Resultados (<span x-text="visibleProducts.length"></span>)</span>
                            <span x-show="!searchQuery">Productos disponibles</span>
                        </h3>
                        <span class="text-xs text-gray-400" x-show="loadingProducts">Cargando…</span>
                    </header>

                    <div class="flex-1 overflow-y-auto p-2.5"
                         x-show="viewMode === 'grid'">
                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5 gap-2.5"
                             x-show="visibleProducts.length > 0">
                            <template x-for="product in visibleProducts" :key="product.id">
                                <button @click="addToCart(product)"
                                        class="group relative bg-white border border-gray-200 rounded-lg overflow-hidden hover:border-indigo-400 hover:shadow-md transition-all text-left flex flex-col">

                                    <div class="relative aspect-square bg-gray-50 overflow-hidden">
                                        <img :src="product.image_url" :alt="product.name"
                                             loading="lazy"
                                             class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-200"
                                             onerror="this.src='{{ asset('images/placeholder-product.svg') }}'">

                                        <template x-if="product.featured">
                                            <span class="absolute top-1 left-1 bg-amber-500 text-white text-[9px] font-bold px-1.5 py-0.5 rounded-full uppercase">★</span>
                                        </template>

                                        <span class="absolute top-1 right-1 text-[10px] font-bold px-1.5 py-0.5 rounded-full shadow-sm"
                                              :class="product.quantity > 10 ? 'bg-green-100 text-green-700' : product.quantity > 0 ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700'"
                                              x-text="product.quantity"></span>

                                        {{-- Badge si está en carrito --}}
                                        <template x-if="cartHas(product.id)">
                                            <span class="absolute bottom-1 left-1 bg-indigo-600 text-white text-[10px] font-bold px-1.5 py-0.5 rounded-full shadow flex items-center gap-1">
                                                ✓ <span x-text="cartQty(product.id)"></span>
                                            </span>
                                        </template>
                                    </div>

                                    <div class="p-2 flex-1 flex flex-col">
                                        <p class="text-xs font-semibold text-gray-900 line-clamp-2 leading-tight"
                                           x-text="product.name"></p>
                                        <p class="text-[10px] text-gray-500 font-mono mt-0.5" x-text="product.sku"></p>
                                        <div class="mt-auto pt-1.5 flex items-center justify-between">
                                            <span class="text-sm font-bold text-indigo-600" x-text="formatCurrency(product.selling_price)"></span>
                                            <span class="text-[10px] text-gray-400" x-text="product.unit?.symbol || ''"></span>
                                        </div>
                                    </div>

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

                    {{-- Vista lista (compacta) --}}
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
                                        <p class="text-xs" :class="product.quantity > 0 ? 'text-green-600' : 'text-red-600'"
                                           x-text="'Stock: ' + product.quantity"></p>
                                    </div>
                                    <template x-if="cartHas(product.id)">
                                        <span class="bg-indigo-600 text-white text-xs font-bold px-2 py-1 rounded-full shrink-0">
                                            ✓ <span x-text="cartQty(product.id)"></span>
                                        </span>
                                    </template>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ============ COLUMNA DERECHA: Carrito sidebar (siempre visible desktop) ============ --}}
            <aside class="bg-white rounded-xl shadow-sm border border-gray-200 flex-col overflow-hidden hidden lg:flex"
                   :class="cartDrawerOpen ? 'fixed inset-x-2 bottom-2 top-12 z-40 lg:static lg:inset-auto flex' : ''">

                <header class="px-4 py-3 border-b border-gray-200 bg-gradient-to-r from-indigo-50 to-purple-50 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wide">
                            Carrito <span class="text-indigo-600" x-text="'(' + cart.length + ')'"></span>
                        </h2>
                    </div>
                    <button @click="cartDrawerOpen = false; resetForm(); clearStorage()"
                            x-show="cart.length > 0"
                            class="text-xs text-red-500 hover:text-red-700"
                            title="Vaciar carrito">
                        Vaciar
                    </button>
                </header>

                {{-- Items scrollables --}}
                <div class="flex-1 overflow-y-auto">
                    <template x-if="cart.length === 0">
                        <div class="flex flex-col items-center justify-center py-16 px-6 text-center">
                            <div class="w-16 h-16 rounded-full bg-gray-100 flex items-center justify-center mb-3">
                                <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            </div>
                            <p class="text-sm font-medium text-gray-900">El carrito está vacío</p>
                            <p class="text-xs text-gray-500 mt-1">Toca un producto del catálogo para agregarlo.</p>
                        </div>
                    </template>

                    <div class="divide-y divide-gray-100">
                        <template x-for="(item, index) in cart" :key="item.id">
                            <div class="p-3 hover:bg-gray-50 transition-colors">
                                <div class="flex items-start gap-2 mb-2">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-semibold text-gray-900 line-clamp-2 leading-snug" x-text="item.name"></p>
                                        <p class="text-[10px] text-gray-500 font-mono" x-text="item.sku"></p>
                                    </div>
                                    <button @click="removeFromCart(index)"
                                            class="w-6 h-6 rounded-full bg-red-50 text-red-600 hover:bg-red-100 shrink-0 flex items-center justify-center">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>

                                <div class="flex items-center justify-between gap-2">
                                    {{-- Qty controls --}}
                                    <div class="inline-flex items-center gap-1 bg-white border border-gray-200 rounded-lg">
                                        <button @click="item.quantity > 1 && item.quantity--; validateQty(index)"
                                                class="w-7 h-7 rounded-l-lg hover:bg-gray-100 text-gray-700 font-bold">−</button>
                                        <input type="number" x-model.number="item.quantity" min="1" :max="item.max_stock"
                                               @input="validateQty(index)"
                                               class="w-10 text-center text-sm border-0 focus:ring-0 p-0">
                                        <button @click="item.quantity < item.max_stock && item.quantity++; validateQty(index)"
                                                class="w-7 h-7 rounded-r-lg hover:bg-gray-100 text-gray-700 font-bold">+</button>
                                    </div>

                                    {{-- Precio unitario --}}
                                    <div class="text-right">
                                        <p class="text-[10px] text-gray-500" x-text="formatCurrency(item.price) + ' c/u'"></p>
                                        <p class="text-sm font-bold text-indigo-600"
                                           x-text="formatCurrency((item.price - item.discount) * item.quantity)"></p>
                                    </div>
                                </div>

                                {{-- Descuento por línea (colapsable) --}}
                                <details class="mt-2" x-show="item.discount > 0 || false">
                                    <summary class="text-[10px] text-gray-500 cursor-pointer hover:text-gray-700">
                                        Descuento: <span x-text="formatCurrency(item.discount)"></span>
                                    </summary>
                                    <div class="mt-1 flex items-center gap-1">
                                        <span class="text-[10px] text-gray-500" x-text="window.currencySymbol"></span>
                                        <input type="text" :value="formatNumber(item.discount)"
                                               @input="item.discount = unformatNumber($event.target.value)"
                                               class="w-full text-xs border-gray-300 rounded px-2 py-1" placeholder="0">
                                    </div>
                                </details>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Footer: totales + botón continuar --}}
                <footer class="border-t border-gray-200 bg-gray-50 p-3 space-y-3" x-show="cart.length > 0">
                    <div class="space-y-1.5">
                        <div class="flex justify-between text-xs text-gray-600">
                            <span>Subtotal (<span x-text="cart.reduce((s,i)=>s+i.quantity,0)"></span> ítems)</span>
                            <span class="font-medium" x-text="formatCurrency(subtotal)"></span>
                        </div>
                        <div class="flex justify-between text-xs text-red-500" x-show="totalDiscount > 0">
                            <span>Descuento ítems</span>
                            <span x-text="'−' + formatCurrency(totalDiscount)"></span>
                        </div>
                        <div class="flex justify-between text-xs text-red-500" x-show="globalDiscount > 0">
                            <span>Descuento global</span>
                            <span x-text="'−' + formatCurrency(globalDiscount)"></span>
                        </div>
                        <div class="flex items-center justify-between pt-2 border-t border-gray-200">
                            <span class="text-sm font-bold text-gray-800">TOTAL</span>
                            <span class="text-2xl font-extrabold text-indigo-600" x-text="formatCurrency(total)"></span>
                        </div>
                    </div>

                    <button @click="openPaymentSheet()"
                            class="w-full flex items-center justify-center gap-2 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 text-white text-sm font-bold rounded-lg shadow-sm transition-all">
                        Continuar al pago
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                    </button>
                    <p class="text-[10px] text-center text-gray-400">Presiona F3 para continuar</p>
                </footer>
            </aside>
        </div>

        {{-- ============ Mobile floating cart button ============ --}}
        <button @click="cartDrawerOpen = true"
                x-show="cart.length > 0 && !cartDrawerOpen && !paymentSheetOpen"
                x-cloak
                class="lg:hidden fixed bottom-4 right-4 z-30 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-full shadow-2xl px-5 py-3.5 flex items-center gap-2 hover:scale-105 transition-transform">
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

        {{-- ============ PAYMENT SHEET (modal full-screen en móvil, modal centrado desktop) ============ --}}
        <div x-show="paymentSheetOpen" x-cloak
             class="fixed inset-0 z-50 flex items-end sm:items-center justify-center"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100">
            <div class="absolute inset-0 bg-black/50" @click="paymentSheetOpen = false"></div>

            <div class="relative bg-white w-full sm:max-w-2xl sm:rounded-2xl sm:my-8 max-h-[100vh] sm:max-h-[90vh] flex flex-col shadow-2xl"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="translate-y-full sm:translate-y-0 sm:scale-95 sm:opacity-0"
                 x-transition:enter-end="translate-y-0 sm:scale-100 sm:opacity-100">

                <header class="px-5 py-4 border-b border-gray-200 flex items-center justify-between sticky top-0 bg-white z-10 rounded-t-2xl">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">Finalizar venta</h2>
                        <p class="text-xs text-gray-500"><span x-text="cart.reduce((s,i)=>s+i.quantity,0)"></span> ítems · <span x-text="formatCurrency(total)" class="font-semibold text-indigo-600"></span></p>
                    </div>
                    <button @click="paymentSheetOpen = false" class="w-9 h-9 rounded-full hover:bg-gray-100 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </header>

                <div class="flex-1 overflow-y-auto p-5 space-y-5">

                    {{-- Cliente --}}
                    <section>
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wide">Cliente</h3>
                            <button @click="openCustomerModal()" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800">+ Nuevo (F4)</button>
                        </div>
                        <div class="bg-indigo-50 border border-indigo-100 rounded-lg p-3">
                            <template x-if="selectedCustomer">
                                <div class="flex justify-between items-center">
                                    <div class="min-w-0">
                                        <h4 class="font-bold text-base text-gray-900 truncate" x-text="selectedCustomer.name"></h4>
                                        <p class="text-sm text-gray-600 truncate" x-text="selectedCustomer.phone || 'Sin teléfono'"></p>
                                    </div>
                                    <button @click="resetCustomer()" class="text-gray-400 hover:text-red-500 shrink-0 ml-2">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                            </template>
                            <div x-show="!selectedCustomer">
                                <select x-ref="customerSelect" placeholder="Buscar cliente [F2]…" autocomplete="off"></select>
                            </div>
                        </div>
                    </section>

                    {{-- Resumen de ítems (compacto) --}}
                    <section>
                        <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">Resumen</h3>
                        <div class="bg-gray-50 rounded-lg divide-y divide-gray-200 max-h-44 overflow-y-auto">
                            <template x-for="item in cart" :key="'sum-'+item.id">
                                <div class="px-3 py-2 flex items-center gap-2">
                                    <span class="text-xs font-bold text-indigo-600 bg-indigo-100 rounded-full w-6 h-6 flex items-center justify-center shrink-0" x-text="item.quantity"></span>
                                    <span class="flex-1 text-sm text-gray-700 truncate" x-text="item.name"></span>
                                    <span class="text-sm font-semibold text-gray-900" x-text="formatCurrency((item.price - item.discount) * item.quantity)"></span>
                                </div>
                            </template>
                        </div>
                    </section>

                    {{-- Totales detallados --}}
                    <section class="space-y-2">
                        <div class="flex justify-between text-sm text-gray-600">
                            <span>Subtotal</span>
                            <span class="font-medium" x-text="formatCurrency(subtotal)"></span>
                        </div>
                        <div class="flex justify-between items-center text-sm">
                            <span class="text-gray-500">Descuento global</span>
                            <div class="relative w-32">
                                <span class="absolute left-2 top-1/2 -translate-y-1/2 text-gray-400 text-xs" x-text="window.currencySymbol"></span>
                                <input type="text" :value="formatNumber(globalDiscount)"
                                       @input="globalDiscount = unformatNumber($event.target.value)"
                                       class="block w-full pl-7 pr-2 py-1.5 text-sm text-right border-gray-300 rounded-md" placeholder="0">
                            </div>
                        </div>
                        <div class="flex justify-between text-sm text-red-500" x-show="totalDiscount > 0">
                            <span>Descuento ítems</span>
                            <span x-text="'−' + formatCurrency(totalDiscount)"></span>
                        </div>
                        <div class="flex justify-between items-center pt-3 border-t border-gray-200">
                            <span class="text-base font-bold text-gray-800">Total a pagar</span>
                            <span class="text-3xl font-extrabold text-indigo-600" x-text="formatCurrency(total)"></span>
                        </div>
                    </section>

                    {{-- Forma de pago --}}
                    <section>
                        <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">Forma de pago</h3>
                        <div class="grid grid-cols-2 gap-2">
                            <button @click="payment.method = 'cash'"
                                    class="py-3 px-4 text-sm font-bold rounded-lg border-2 transition-all flex items-center justify-center gap-2"
                                    :class="payment.method === 'cash' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-200 hover:border-gray-300'">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                EFECTIVO
                            </button>
                            <button @click="payment.method = 'transfer'"
                                    class="py-3 px-4 text-sm font-bold rounded-lg border-2 transition-all flex items-center justify-center gap-2"
                                    :class="payment.method === 'transfer' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-200 hover:border-gray-300'">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                                TRANSFERENCIA
                            </button>
                        </div>
                    </section>

                    <template x-if="payment.method === 'cash'">
                        <section>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">Efectivo recibido</label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-lg font-bold text-gray-500" x-text="window.currencySymbol"></span>
                                {{-- Input "draft": user escribe libre (sin reformateo en cada keystroke).
                                     Solo al perder foco (blur) se aplica formato lindo con miles + decimales. --}}
                                <input type="text"
                                       inputmode="decimal"
                                       x-model="cashInputDraft"
                                       @focus="onCashFocus($event)"
                                       @input="payment.cash_received = unformatNumber(cashInputDraft)"
                                       @blur="onCashBlur()"
                                       class="block w-full py-3.5 pl-12 pr-3 text-2xl font-bold text-right border-2 border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="0">
                            </div>

                            <div class="mt-3 p-3 rounded-lg flex justify-between items-center"
                                 :class="change < 0 ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200'">
                                <span class="text-sm font-bold uppercase"
                                      :class="change < 0 ? 'text-red-700' : 'text-green-700'"
                                      x-text="change < 0 ? 'Falta por pagar' : 'Cambio a entregar'"></span>
                                <span class="text-2xl font-extrabold"
                                      :class="change < 0 ? 'text-red-700' : 'text-green-700'"
                                      x-text="formatCurrency(Math.abs(change))"></span>
                            </div>

                            {{-- Atajos billetes comunes --}}
                            <div class="mt-3 grid grid-cols-4 gap-2">
                                <template x-for="amount in [Math.ceil(total/100), 50, 100, 200]" :key="amount">
                                    <button type="button"
                                            @click="setCashFromShortcut(amount)"
                                            class="py-2 text-xs font-bold border border-gray-200 rounded-lg hover:bg-indigo-50 hover:border-indigo-300 transition-colors"
                                            x-text="amount === Math.ceil(total/100) ? 'Exacto' : (window.currencySymbol + ' ' + amount)"></button>
                                </template>
                            </div>
                        </section>
                    </template>

                    <section>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">Notas (opcional)</label>
                        <textarea x-model="payment.notes" rows="2"
                                  class="block w-full text-sm border-gray-300 rounded-lg p-2.5"
                                  placeholder="Dirección de entrega, observaciones…"></textarea>
                    </section>

                    {{-- Estado venta --}}
                    <section>
                        <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">Estado</h3>
                        <div class="grid grid-cols-2 gap-2">
                            <button @click="saleStatus = 'completed'"
                                    class="py-2 px-3 text-xs font-bold rounded-lg border transition-all"
                                    :class="saleStatus === 'completed' ? 'bg-green-600 text-white border-green-600' : 'bg-white text-gray-700 border-gray-200'">
                                ✓ COMPLETAR AHORA
                            </button>
                            <button @click="saleStatus = 'pending'"
                                    class="py-2 px-3 text-xs font-bold rounded-lg border transition-all"
                                    :class="saleStatus === 'pending' ? 'bg-yellow-500 text-white border-yellow-500' : 'bg-white text-gray-700 border-gray-200'">
                                ⏱ RESERVAR
                            </button>
                        </div>
                    </section>
                </div>

                {{-- Footer modal con acciones --}}
                <footer class="border-t border-gray-200 bg-gray-50 p-4 flex gap-2 sticky bottom-0 rounded-b-2xl">
                    <button @click="paymentSheetOpen = false"
                            class="px-5 py-3 text-sm font-bold text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-100">
                        Atrás
                    </button>
                    <button @click="submitSale()"
                            :disabled="isSubmitting || (payment.method === 'cash' && payment.cash_received < total)"
                            class="flex-1 py-3 text-base font-extrabold text-white rounded-lg shadow-sm disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                            :class="saleStatus === 'completed' ? 'bg-gradient-to-r from-green-600 to-emerald-600 hover:from-green-700 hover:to-emerald-700' : 'bg-gradient-to-r from-yellow-500 to-amber-500 hover:from-yellow-600 hover:to-amber-600'">
                        <template x-if="isSubmitting">
                            <svg class="animate-spin -ml-1 mr-2 h-5 w-5 text-white inline" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        </template>
                        <span x-text="isSubmitting ? 'Procesando…' : (saleStatus === 'completed' ? 'PROCESAR VENTA' : 'RESERVAR PEDIDO')"></span>
                    </button>
                </footer>
            </div>
        </div>

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

                    products: [],
                    visibleProducts: [],
                    loadingProducts: false,
                    searchQuery: '',
                    viewMode: 'grid',
                    cartDrawerOpen: false,
                    paymentSheetOpen: false,

                    // Draft del input de efectivo: string libre que muestra exactamente
                    // lo que el usuario tipea. Sin reformateo en cada keystroke = el
                    // cursor no salta al final ni reinterpreta los dígitos como centavos.
                    cashInputDraft: '',

                    productTs: null,
                    customerTs: null,

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

                        // Inicializa el draft del input efectivo con el valor formateado
                        // (por si venía persistido en localStorage de una sesión anterior).
                        this.cashInputDraft = this.payment.cash_received > 0
                            ? this.formatNumber(this.payment.cash_received)
                            : '';

                        this.loadProducts('');
                        this.initCustomerSelect();
                    },

                    /**
                     * Al hacer focus: mostrar el valor "crudo" editable (sin separadores
                     * de miles para evitar confusión al teclear). Si el draft vino
                     * formateado (ej "1.234,56"), lo convierte a editable ("1234.56").
                     */
                    onCashFocus(e) {
                        if (this.payment.cash_received === 0) {
                            this.cashInputDraft = '';
                        } else {
                            // Solo número editable: stripear thousand separator, mantener decimal.
                            const cents = this.payment.cash_received;
                            const bs = (cents / 100).toFixed(2);
                            // Mostrar con el decimal local pero sin separador de miles para edición.
                            this.cashInputDraft = bs.replace('.', window.decimalSeparator);
                        }
                        // Seleccionar todo el contenido para que el primer dígito reemplace.
                        this.$nextTick(() => e.target.select());
                    },

                    /**
                     * Al perder foco: reformatea el draft a la versión bonita
                     * con miles + 2 decimales fijos. Si el campo está vacío deja vacío.
                     */
                    onCashBlur() {
                        if (this.payment.cash_received === 0) {
                            this.cashInputDraft = '';
                            return;
                        }
                        this.cashInputDraft = this.formatNumber(this.payment.cash_received);
                    },

                    /**
                     * Botón atajo "Exacto / 50 / 100 / 200": setea el monto + sincroniza
                     * el draft visual del input.
                     */
                    setCashFromShortcut(amount) {
                        this.payment.cash_received = amount * 100;
                        this.cashInputDraft = this.formatNumber(this.payment.cash_received);
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
                        } catch (e) { console.error(e); }
                        finally { this.loadingProducts = false; }
                    },

                    initCustomerSelect() {
                        if (!this.$refs.customerSelect) return;
                        if (this.customerTs) { this.customerTs.destroy(); this.customerTs = null; }

                        this.customerTs = new TomSelect(this.$refs.customerSelect, {
                            valueField: 'value', labelField: 'text', searchField: 'text',
                            preload: 'focus', openOnFocus: true,
                            load: (query, callback) => {
                                fetch('{{ route("ajax.customers.search") }}', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                    },
                                    body: JSON.stringify({ q: query }),
                                }).then(r => r.json()).then(callback).catch(() => callback());
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
                                this.$dispatch('toast', { message: 'Cantidad: ' + existing.quantity, type: 'info' });
                            } else {
                                this.$dispatch('toast', { message: '¡Stock máximo alcanzado!', type: 'error' });
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

                    cartHas(productId) { return this.cart.some(i => i.id === productId); },
                    cartQty(productId) {
                        const item = this.cart.find(i => i.id === productId);
                        return item ? item.quantity : 0;
                    },

                    validateQty(index) {
                        const item = this.cart[index];
                        if (item.quantity > item.max_stock) {
                            item.quantity = item.max_stock;
                            this.$dispatch('toast', { message: 'Máximo: ' + item.max_stock, type: 'warning' });
                        }
                        if (item.quantity < 1) item.quantity = 1;
                    },

                    removeFromCart(index) {
                        const removed = this.cart[index];
                        this.cart.splice(index, 1);
                        this.$dispatch('toast', { message: 'Quitado: ' + removed.name, type: 'info' });
                    },

                    openCustomerModal() {
                        this.$dispatch('open-modal', { name: 'customer-modal' });
                        this.$nextTick(() => setTimeout(() => this.$refs.nameInput && this.$refs.nameInput.focus(), 100));
                    },

                    openPaymentSheet() {
                        if (this.cart.length === 0) {
                            this.$dispatch('toast', { message: 'Agrega al menos un producto', type: 'warning' });
                            return;
                        }
                        this.cartDrawerOpen = false;
                        this.paymentSheetOpen = true;
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

                    async submitSale() {
                        if (this.payment.method === 'cash' && this.payment.cash_received < this.total) {
                            this.$dispatch('toast', { message: '¡Pago insuficiente!', type: 'error' });
                            return;
                        }
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
                                    'Content-Type': 'application/json', 'Accept': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                },
                                body: JSON.stringify(payload),
                            });
                            const data = await res.json();
                            if (res.ok && data.success) {
                                this.paymentSheetOpen = false;
                                this.cartDrawerOpen = false;
                                if (data.print_url) window.open(data.print_url, '_blank');
                                this.clearStorage();
                                this.resetForm();
                                this.$dispatch('toast', { message: '¡Venta exitosa!', type: 'success' });
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
                        this.customerTs && this.customerTs.clear();
                    }
                }
            }
        </script>

        {{-- Customer create modal --}}
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
                    <h3 class="text-lg font-semibold">Crear nuevo cliente</h3>
                    <p class="text-sm text-muted-foreground">Agregue un nuevo cliente a sus registros.</p>
                </div>
                <div class="space-y-4">
                    <div>
                        <x-form-input name="new_name" label="Nombre completo" x-model="newCust.name" x-ref="nameInput" required />
                        <p x-show="errors.name" x-text="errors.name" class="text-sm text-red-600 mt-1" style="display:none"></p>
                    </div>
                    <div class="flex flex-col sm:flex-row gap-4">
                        <div class="w-full sm:w-1/2">
                            <x-form-input name="new_email" label="Email" type="email" x-model="newCust.email" />
                        </div>
                        <div class="w-full sm:w-1/2">
                            <x-form-input name="new_phone" label="Teléfono" x-model="newCust.phone" />
                        </div>
                    </div>
                    <div class="space-y-2">
                        <x-input-label for="new_address" :value="__('Dirección')" />
                        <textarea id="new_address" x-model="newCust.address" rows="2" class="block w-full rounded-md border-gray-300 sm:text-sm" placeholder="Dirección"></textarea>
                    </div>
                    <div class="mt-6 flex justify-end gap-3 border-t border-gray-200 pt-4">
                        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', { name: 'customer-modal' })">Cancelar</x-secondary-button>
                        <x-primary-button type="button" @click="save()" x-bind:disabled="loading">
                            <span x-text="loading ? 'Guardando…' : 'Guardar cliente'"></span>
                        </x-primary-button>
                    </div>
                </div>
            </div>
        </x-modal>

        {{-- Customer-created listener --}}
        <div @customer-created.window="selectedCustomer = $event.detail;"></div>
    </div>
</x-app-layout>
