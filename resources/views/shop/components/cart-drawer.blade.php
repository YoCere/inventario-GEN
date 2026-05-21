@php
    use App\Models\Setting;
    $currencySymbol = Setting::get('shop_currency_symbol', 'Bs.');
@endphp

{{-- Backdrop --}}
<div x-data
     x-show="$store.cart.open"
     x-transition.opacity
     x-cloak
     @click="$store.cart.close()"
     class="fixed inset-0 bg-black/40 backdrop-blur-sm z-40"
     aria-hidden="true"></div>

{{-- Drawer slide-in derecha --}}
<aside x-data
       x-show="$store.cart.open"
       x-transition:enter="transition ease-out duration-300"
       x-transition:enter-start="translate-x-full"
       x-transition:enter-end="translate-x-0"
       x-transition:leave="transition ease-in duration-200"
       x-transition:leave-start="translate-x-0"
       x-transition:leave-end="translate-x-full"
       x-cloak
       @keydown.escape.window="$store.cart.close()"
       class="fixed top-0 right-0 h-full w-full sm:w-96 bg-white shadow-2xl z-50 flex flex-col"
       aria-label="Carrito de compras">

    {{-- Header --}}
    <header class="px-5 py-4 border-b border-zinc-200 flex items-center justify-between">
        <h2 class="font-bold text-lg">Tu carrito <span class="text-zinc-400 font-normal text-sm" x-text="`(${$store.cart.count()})`"></span></h2>
        <button @click="$store.cart.close()" class="p-1.5 hover:bg-zinc-100 rounded-full" aria-label="Cerrar">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </header>

    {{-- Empty state --}}
    <template x-if="$store.cart.items.length === 0">
        <div class="flex-1 flex flex-col items-center justify-center px-6 text-center">
            <div class="w-20 h-20 rounded-full bg-zinc-100 flex items-center justify-center mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-zinc-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <circle cx="9" cy="21" r="1"/>
                    <circle cx="20" cy="21" r="1"/>
                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                </svg>
            </div>
            <p class="font-medium text-zinc-900">Tu carrito está vacío</p>
            <p class="text-sm text-zinc-500 mt-1">Añade productos del catálogo para comenzar.</p>
            <button @click="$store.cart.close()" class="mt-6 shop-btn-primary">
                Explorar productos
            </button>
        </div>
    </template>

    {{-- Items list --}}
    <div x-show="$store.cart.items.length > 0" class="flex-1 overflow-y-auto px-5 py-4 space-y-3">
        <template x-for="item in $store.cart.items" :key="item.id">
            <div class="flex gap-3 p-3 rounded-xl border border-zinc-100 hover:bg-zinc-50">
                <img :src="item.image" :alt="item.name" class="w-16 h-16 object-cover rounded-lg bg-zinc-100 shrink-0">
                <div class="flex-1 min-w-0">
                    <a :href="`/tienda/producto/${item.slug}`" class="font-medium text-sm text-zinc-900 line-clamp-2 hover:underline" x-text="item.name"></a>
                    <p class="text-xs text-zinc-500 mt-0.5">
                        {{ $currencySymbol }} <span x-text="(item.price/100).toFixed(2)"></span> c/u
                    </p>

                    <div class="flex items-center justify-between mt-2 gap-2">
                        <div class="inline-flex items-center border border-zinc-200 rounded-lg">
                            <button @click="$store.cart.decrement(item.id)" class="w-7 h-7 flex items-center justify-center text-zinc-600 hover:bg-zinc-100 rounded-l-lg" aria-label="Disminuir">−</button>
                            <span class="w-8 text-center text-sm font-medium" x-text="item.qty"></span>
                            <button @click="$store.cart.increment(item.id)" class="w-7 h-7 flex items-center justify-center text-zinc-600 hover:bg-zinc-100 rounded-r-lg" aria-label="Aumentar">+</button>
                        </div>
                        <button @click="$store.cart.remove(item.id)" class="text-xs text-zinc-400 hover:text-red-600">Eliminar</button>
                    </div>
                </div>
                <div class="font-semibold text-sm shrink-0" :style="`color: var(--shop-primary)`">
                    {{ $currencySymbol }} <span x-text="((item.price * item.qty)/100).toFixed(2)"></span>
                </div>
            </div>
        </template>
    </div>

    {{-- Footer con total + checkout --}}
    <footer x-show="$store.cart.items.length > 0" x-cloak class="border-t border-zinc-200 px-5 py-4 space-y-3 bg-white">
        <div class="flex items-center justify-between">
            <span class="text-zinc-600 text-sm">Total estimado</span>
            <span class="text-xl font-bold" :style="`color: var(--shop-primary)`">
                {{ $currencySymbol }} <span x-text="$store.cart.totalFormatted()"></span>
            </span>
        </div>
        <a href="{{ route('shop.checkout') }}" class="shop-btn-primary w-full">
            Reservar pedido →
        </a>
        <button @click="$store.cart.clear()" class="w-full text-xs text-zinc-400 hover:text-red-600">
            Vaciar carrito
        </button>
    </footer>
</aside>
