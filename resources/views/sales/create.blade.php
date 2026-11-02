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
                            class="flex items-center justify-center w-11 h-11 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors shrink-0"
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
            {{-- responsive: base hidden, lg always flex; mobile drawer uses !flex override via Alpine --}}
            <aside class="bg-white rounded-xl shadow-sm border border-gray-200 flex-col overflow-hidden hidden lg:flex"
                   :class="cartDrawerOpen ? '!flex fixed inset-x-2 bottom-2 top-12 z-40' : ''">

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

                {{-- ── Cabecera: lo mínimo para saber dónde estás ───────────────── --}}
                <header class="px-5 py-3 border-b border-gray-200 flex items-center justify-between sticky top-0 bg-white z-10 rounded-t-2xl">
                    <h2 class="text-base font-bold text-gray-900">
                        Cobrar
                        <span class="ml-1 text-sm font-normal text-gray-500">
                            <span x-text="cart.reduce((s,i)=>s+i.quantity,0)"></span> ítems
                        </span>
                    </h2>
                    <button @click="paymentSheetOpen = false" class="w-10 h-10 -mr-2 rounded-full hover:bg-gray-100 flex items-center justify-center" aria-label="Volver al carrito">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </header>

                <div class="flex-1 overflow-y-auto px-5 py-4 space-y-5">

                    {{-- ── Lo único que importa siempre: cuánto se cobra ───────────── --}}
                    <section class="text-center">
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Total a pagar</p>
                        <p class="mt-1 text-5xl font-extrabold text-gray-900 tabular-nums leading-none" x-text="formatCurrency(total)"></p>
                        <p class="mt-1 text-xs text-red-500" x-show="totalDiscount + globalDiscount > 0">
                            Descuentos: <span x-text="'−' + formatCurrency(totalDiscount + globalDiscount)"></span>
                        </p>
                    </section>

                    {{-- ── Forma de pago: una fila, sin ocupar media pantalla ──────── --}}
                    <section>
                        <div class="grid grid-cols-4 gap-2">
                            <template x-for="forma in formasDePago" :key="forma.id">
                                <button type="button" @click="payment.method = forma.id"
                                        class="py-2.5 px-1 rounded-xl border-2 transition-colors flex flex-col items-center gap-1"
                                        :class="payment.method === forma.id ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-600 border-gray-200'">
                                    <span class="text-lg leading-none" x-text="forma.icono"></span>
                                    <span class="text-[11px] font-bold leading-none" x-text="forma.nombre"></span>
                                </button>
                            </template>
                        </div>
                    </section>

                    {{-- ── Efectivo: pagar con un toque ────────────────────────────── --}}
                    {{-- x-show y no x-if: así el campo conserva su estado al cambiar
                         de forma de pago y volver. --}}
                    <section x-show="payment.method === 'cash'" class="space-y-3">
                        <div class="grid grid-cols-2 gap-2">
                            {{-- "Exacto" primero: es lo más frecuente de lejos. --}}
                            <button type="button"
                                    @click="payment.cash_received = total; cashInputDraft = formatNumber(total)"
                                    class="py-4 rounded-xl border-2 font-bold transition-colors"
                                    :class="payment.cash_received === total && total > 0
                                        ? 'bg-emerald-600 text-white border-emerald-600'
                                        : 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:border-emerald-400'">
                                <span class="block text-xs font-semibold uppercase tracking-wide opacity-80">Justo</span>
                                <span class="block text-lg tabular-nums" x-text="formatCurrency(total)"></span>
                            </button>

                            {{-- Los billetes salen del total, no de una lista fija: para
                                 350 sirve 400 y 500, no "50". --}}
                            <template x-for="monto in montosSugeridos" :key="'sug-'+monto">
                                <button type="button"
                                        @click="payment.cash_received = monto; cashInputDraft = formatNumber(monto)"
                                        class="py-4 rounded-xl border-2 font-bold text-lg tabular-nums transition-colors"
                                        :class="payment.cash_received === monto
                                            ? 'bg-indigo-600 text-white border-indigo-600'
                                            : 'bg-white text-gray-700 border-gray-200 hover:border-gray-400'"
                                        x-text="formatCurrency(monto)"></button>
                            </template>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">
                                Otro monto
                            </label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-lg font-bold text-gray-400" x-text="window.currencySymbol"></span>
                                <input type="text"
                                       inputmode="decimal"
                                       x-model="cashInputDraft"
                                       @focus="onCashFocus($event)"
                                       @input="payment.cash_received = unformatNumber(cashInputDraft)"
                                       @blur="onCashBlur()"
                                       class="block w-full py-3 pl-12 pr-3 text-2xl font-bold text-right tabular-nums text-gray-900 bg-white border-2 border-gray-200 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                       placeholder="0">
                            </div>
                        </div>

                        {{-- El vuelto es lo que la persona necesita leer de lejos y
                             rápido, con el cliente esperando. --}}
                    </section>

                    {{-- ── Todo lo demás, guardado hasta que haga falta ────────────── --}}
                    <section>
                        <button type="button" @click="masOpciones = !masOpciones"
                                class="w-full flex items-center justify-between py-3 text-sm font-semibold text-gray-600 border-t border-gray-200">
                            <span>
                                Más opciones
                                {{-- Si algo de lo plegado está en uso, se avisa: nada
                                     que afecte la venta puede quedar escondido. --}}
                                <span x-show="selectedCustomer || globalDiscount > 0 || payment.notes || saleStatus !== 'completed' || wantsInvoice"
                                      class="ml-1 inline-block w-2 h-2 rounded-full bg-indigo-500 align-middle"></span>
                            </span>
                            <svg class="w-5 h-5 transition-transform" :class="masOpciones && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        {{-- x-show (no x-if): el selector de cliente se inicializa una
                             sola vez al cargar la pantalla; sacarlo del DOM lo rompería. --}}
                        <div x-show="masOpciones" x-transition class="space-y-5 pb-1">

                            {{-- Cliente --}}
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wide">Cliente</h3>
                                    <button @click="openCustomerModal()" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800">+ Nuevo (F4)</button>
                                </div>
                                <div class="bg-gray-50 border border-gray-200 rounded-xl p-3">
                                    <template x-if="selectedCustomer">
                                        <div class="flex justify-between items-center">
                                            <div class="min-w-0">
                                                <h4 class="font-bold text-base text-gray-900 truncate" x-text="selectedCustomer.name"></h4>
                                                <p class="text-sm text-gray-600 truncate" x-text="selectedCustomer.phone || 'Sin teléfono'"></p>
                                            </div>
                                            <button @click="resetCustomer()" class="text-gray-400 hover:text-red-500 shrink-0 ml-2" aria-label="Quitar cliente">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </div>
                                    </template>
                                    <div x-show="!selectedCustomer">
                                        <select x-ref="customerSelect" placeholder="Buscar cliente [F2]…" autocomplete="off"></select>
                                    </div>
                                </div>

                                @if(\App\Models\Setting::get('facturacion_activada','0') === '1')
                                    <label class="mt-2 flex items-center gap-2 text-sm text-gray-700 cursor-pointer select-none">
                                        <input type="checkbox" x-model="wantsInvoice"
                                               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                        ¿Factura? (NIT/CI)
                                    </label>
                                    <p class="mt-1 text-xs text-amber-600" x-show="wantsInvoice && !selectedCustomer">
                                        Elegí un cliente con NIT para facturar.
                                    </p>
                                @endif
                            </div>

                            {{-- Qué se está cobrando --}}
                            <div>
                                <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">Qué se lleva</h3>
                                <div class="bg-gray-50 rounded-xl divide-y divide-gray-200 max-h-44 overflow-y-auto">
                                    <template x-for="item in cart" :key="'sum-'+item.id">
                                        <div class="px-3 py-2 flex items-center gap-2">
                                            <span class="text-xs font-bold text-indigo-600 bg-indigo-100 rounded-full w-6 h-6 flex items-center justify-center shrink-0" x-text="item.quantity"></span>
                                            <span class="flex-1 text-sm text-gray-700 truncate" x-text="item.name"></span>
                                            <span class="text-sm font-semibold text-gray-900 tabular-nums" x-text="formatCurrency((item.price - item.discount) * item.quantity)"></span>
                                        </div>
                                    </template>
                                </div>
                                <div class="flex justify-between text-sm text-gray-600 mt-2">
                                    <span>Subtotal</span>
                                    <span class="font-medium tabular-nums" x-text="formatCurrency(subtotal)"></span>
                                </div>
                            </div>

                            {{-- Descuento sobre el total --}}
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-gray-600">Descuento</span>
                                <div class="relative w-32">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm" x-text="window.currencySymbol"></span>
                                    <input type="text" :value="formatNumber(globalDiscount)"
                                           @input="globalDiscount = unformatNumber($event.target.value)"
                                           inputmode="decimal"
                                           class="block w-full pl-8 pr-2 py-2 text-sm text-right tabular-nums text-gray-900 bg-white border-gray-300 rounded-lg" placeholder="0">
                                </div>
                            </div>

                            {{-- Notas --}}
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5">Notas</label>
                                <textarea x-model="payment.notes" rows="2"
                                          class="block w-full text-sm text-gray-900 bg-white border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                                          placeholder="Dirección de entrega, observaciones…"></textarea>
                            </div>

                            {{-- Reservar en vez de cobrar --}}
                            <div>
                                <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">¿Se lleva ahora?</h3>
                                <div class="grid grid-cols-2 gap-2">
                                    <button @click="saleStatus = 'completed'"
                                            class="py-2.5 px-3 text-xs font-bold rounded-lg border-2 transition-colors"
                                            :class="saleStatus === 'completed' ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-gray-700 border-gray-200'">
                                        Sí, venta hecha
                                    </button>
                                    <button @click="saleStatus = 'pending'"
                                            class="py-2.5 px-3 text-xs font-bold rounded-lg border-2 transition-colors"
                                            :class="saleStatus === 'pending' ? 'bg-amber-500 text-white border-amber-500' : 'bg-white text-gray-700 border-gray-200'">
                                        No, es un pedido
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                {{-- ── Un solo botón, y que diga siempre qué pasa ──────────────── --}}
                <footer class="border-t border-gray-200 bg-gray-50 p-4 sticky bottom-0 rounded-b-2xl space-y-3">
                    {{-- El vuelto va acá, pegado al botón: es lo que se lee con el
                         cliente esperando, y en el cuerpo scrolleable quedaba
                         cortado contra el borde de la pantalla. --}}
                    <div x-show="change > 0"
                         x-transition.opacity
                         class="rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-2.5 flex items-center justify-between">
                        <span class="text-sm font-bold text-emerald-800 uppercase tracking-wide">Vuelto</span>
                        <span class="text-3xl font-extrabold text-emerald-700 tabular-nums" x-text="formatCurrency(change)"></span>
                    </div>

                    <button @click="submitSale()"
                            :disabled="isSubmitting || faltante > 0"
                            class="w-full py-4 text-lg font-extrabold text-white rounded-xl shadow-sm disabled:cursor-not-allowed transition-colors"
                            :class="faltante > 0
                                ? 'bg-gray-400'
                                : (saleStatus === 'completed' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-amber-500 hover:bg-amber-600')">
                        <template x-if="isSubmitting">
                            <svg class="animate-spin -ml-1 mr-2 h-5 w-5 text-white inline" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        </template>
                        {{-- Un botón apagado y mudo era el "no se entiende qué falta":
                             ahora dice cuánto falta en vez de solo ponerse gris. --}}
                        <span x-text="isSubmitting
                                ? 'Procesando…'
                                : (faltante > 0
                                    ? 'Faltan ' + formatCurrency(faltante)
                                    : (saleStatus === 'completed'
                                        ? 'Cobrar ' + formatCurrency(total)
                                        : 'Guardar pedido'))"></span>
                    </button>
                </footer>
            </div>
        </div>

        {{-- Alpine component logic --}}
        <script>
            function pos() {
                return {
                    cart: [],

                    // Cuándo empezó ESTA venta: el momento en que entra el primer
                    // producto al carrito. Sin esto el servidor mide desde que la
                    // pantalla quedó libre, y un mostrador vacío de veinte minutos
                    // se anotaría como una venta lenta de veinte minutos.
                    atendidoDesde: null,
                    selectedCustomer: null,
                    payment: { method: 'cash', cash_received: 0, notes: '' },
                    globalDiscount: 0,
                    saleStatus: 'completed',
                    isSubmitting: false,
                    wantsInvoice: false,

                    products: [],
                    visibleProducts: [],
                    loadingProducts: false,
                    searchQuery: '',
                    viewMode: 'grid',
                    cartDrawerOpen: false,
                    paymentSheetOpen: false,

                    // Lo que casi nunca se toca (cliente, descuento, notas, pedido)
                    // arranca plegado: cobrar en efectivo no debería obligar a pasar
                    // por siete bloques.
                    masOpciones: false,

                    formasDePago: [
                        { id: 'cash',     nombre: 'EFECTIVO',  icono: '💵' },
                        { id: 'transfer', nombre: 'TRANSF.',   icono: '🏦' },
                        { id: 'qr',       nombre: 'QR',        icono: '📱' },
                        { id: 'card',     nombre: 'TARJETA',   icono: '💳' },
                    ],

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
                        if (this.cart.length === 0) this.atendidoDesde = new Date().toISOString();
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

                    /** Cuánto falta para poder cobrar. Cero cuando ya se puede. */
                    get faltante() {
                        if (this.payment.method !== 'cash') return 0;
                        return Math.max(0, this.total - this.payment.cash_received);
                    },

                    /**
                     * Con qué billete es probable que paguen, calculado a partir del
                     * total. Antes eran 50, 100 y 200 fijos: para un total de 350
                     * ninguno de los tres servía, que es justo cuando más falta hace.
                     */
                    get montosSugeridos() {
                        const total = this.total;
                        if (total <= 0) return [];

                        // OJO: acá todo viaja en CENTAVOS (un total de Bs 50,50 es
                        // 5050). Calcular los billetes como si fueran bolivianos
                        // ofrecía "Bs 51" para un total de Bs 50,50: un botón inútil.
                        const BS = 100;
                        const aLaProxima = (escalon) => Math.ceil(total / (escalon * BS)) * escalon * BS;

                        const candidatos = [
                            aLaProxima(10),   // la próxima decena de bolivianos
                            aLaProxima(50),
                            aLaProxima(100),
                            ...[10, 20, 50, 100, 200, 500]
                                .map(billete => billete * BS)
                                .filter(billete => billete > total),
                        ];

                        return [...new Set(candidatos)]
                            .filter(monto => monto > total)
                            .sort((a, b) => a - b)
                            .slice(0, 3);
                    },

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
                                _token: '{{ csrf_token() }}',
                                wants_invoice: this.wantsInvoice,
                                atendido_desde: this.atendidoDesde,
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
                        // El texto que se VE en el campo de efectivo vive aparte del
                        // número. Sin esta línea, la venta siguiente arrancaba
                        // mostrando el monto de la anterior (se cobraban 350, se
                        // empezaba la próxima venta y el campo seguía diciendo 350)
                        // mientras por dentro el valor era 0: el vendedor veía un
                        // monto pagado que el sistema no tenía, y al cobrar le
                        // aparecía "pago insuficiente" sin entender por qué.
                        this.cashInputDraft = '';
                        this.atendidoDesde = null;
                        this.globalDiscount = 0;
                        this.wantsInvoice = false;
                        this.customerTs && this.customerTs.clear();
                    }
                }
            }
        </script>

        {{-- Customer create modal --}}
        <x-modal name="customer-modal" focusable>
            <div class="p-6" x-data="{
                newCust: { name: '', email: '', phone: '', address: '', notes: '', doc_type: '', doc_number: '', doc_complement: '', business_name: '' },
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
                            this.newCust = { name: '', email: '', phone: '', address: '', notes: '', doc_type: '', doc_number: '', doc_complement: '', business_name: '' };
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

                    @if(\App\Models\Setting::get('facturacion_activada','0') === '1')
                        {{-- Identidad fiscal (opcional): necesaria para poder facturar esta venta --}}
                        <div class="border-t border-gray-200 pt-4 space-y-3">
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Datos para factura (opcional)</p>
                            <div class="flex flex-col sm:flex-row gap-4">
                                <div class="w-full sm:w-1/2">
                                    <x-input-label for="new_doc_type" :value="__('Tipo de documento')" />
                                    <select id="new_doc_type" x-model="newCust.doc_type"
                                            class="block w-full rounded-md border-gray-300 sm:text-sm">
                                        <option value="">Seleccionar…</option>
                                        <option value="1">CI</option>
                                        <option value="2">CEX</option>
                                        <option value="3">Pasaporte</option>
                                        <option value="4">NIT</option>
                                        <option value="5">Otro</option>
                                    </select>
                                </div>
                                <div class="w-full sm:w-1/2">
                                    <x-form-input name="new_doc_number" label="Número de documento" x-model="newCust.doc_number" />
                                </div>
                            </div>
                            <div class="flex flex-col sm:flex-row gap-4">
                                <div class="w-full sm:w-1/2">
                                    <x-form-input name="new_doc_complement" label="Complemento" x-model="newCust.doc_complement" />
                                </div>
                                <div class="w-full sm:w-1/2">
                                    <x-form-input name="new_business_name" label="Razón social" x-model="newCust.business_name" />
                                </div>
                            </div>
                        </div>
                    @endif

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
