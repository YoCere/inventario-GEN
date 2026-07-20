@extends('shop.layouts.app')

@php
    use App\Models\Setting;
    $currencySymbol = Setting::get('shop_currency_symbol', 'Bs.');
    $businessName = Setting::get('shop_business_name') ?: config('app.name');
@endphp

@section('title', 'Confirmar pedido')

@section('content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8"
     x-data="checkoutForm()">

    <nav class="text-sm text-zinc-500 mb-6">
        <a href="{{ route('shop.index') }}" class="hover:text-zinc-900">Inicio</a>
        <span class="mx-1.5">›</span>
        <span class="text-zinc-900">Confirmar pedido</span>
    </nav>

    {{-- Estado: carrito vacío --}}
    <div x-show="$store.cart.items.length === 0" class="bg-white rounded-2xl border border-zinc-200 p-12 text-center">
        <div class="text-5xl mb-3">🛒</div>
        <h2 class="text-xl font-semibold text-zinc-900">Tu carrito está vacío</h2>
        <p class="text-zinc-500 mt-1 mb-6">Agrega productos del catálogo para reservar.</p>
        <a href="{{ route('shop.catalog') }}" class="shop-btn-primary">Ver catálogo</a>
    </div>

    {{-- Estado: carrito con items --}}
    <div x-show="$store.cart.items.length > 0" x-cloak class="grid lg:grid-cols-[1fr_380px] gap-8">

        {{-- Form datos cliente --}}
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-bold text-zinc-900">Confirma tu pedido</h1>
                <p class="text-zinc-500 mt-1">Te enviaremos un mensaje por WhatsApp para confirmar disponibilidad y forma de pago.</p>
            </div>

            <form @submit.prevent="submit" class="bg-white rounded-2xl border border-zinc-200 p-6 space-y-5">
                <h2 class="font-semibold text-zinc-900">Tus datos</h2>

                <div>
                    <label class="block text-sm font-medium text-zinc-700 mb-1.5">Nombre completo *</label>
                    <input type="text" x-model="buyerName" required minlength="2" maxlength="120"
                           placeholder="Ej: Juan Pérez"
                           class="shop-input">
                </div>

                <div>
                    <label class="block text-sm font-medium text-zinc-700 mb-1.5">Teléfono / WhatsApp *</label>
                    <input type="tel" x-model="buyerPhone" required minlength="6" maxlength="30"
                           placeholder="Ej: 70012345"
                           class="shop-input">
                    <p class="text-xs text-zinc-500 mt-1">Usaremos este número para coordinar tu pedido.</p>
                </div>

                <div class="rounded-lg p-3 text-sm" style="background: color-mix(in srgb, var(--shop-primary) 8%, white); color: var(--shop-primary)">
                    <p class="font-medium mb-1">¿Cómo funciona?</p>
                    <ol class="list-decimal list-inside text-xs space-y-0.5 opacity-90">
                        <li>Tocas "Reservar y enviar por WhatsApp".</li>
                        <li>Guardamos tu pedido como reserva pendiente.</li>
                        <li>Te abrimos WhatsApp con el detalle pre-armado.</li>
                        <li>Confirmas envío al chat del negocio.</li>
                        <li>Te respondemos con la forma de pago y entrega.</li>
                    </ol>
                </div>

                {{-- Error global --}}
                <template x-if="errorMessage">
                    <div class="rounded-lg bg-red-50 border border-red-200 p-3 text-sm text-red-800">
                        <p class="font-medium" x-text="errorMessage"></p>
                    </div>
                </template>

                <button type="submit"
                        :disabled="loading || $store.cart.items.length === 0"
                        class="shop-btn-primary w-full py-3 text-base disabled:opacity-50 disabled:cursor-not-allowed">
                    <template x-if="!loading">
                        <span class="inline-flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                            Reservar y enviar por WhatsApp
                        </span>
                    </template>
                    <template x-if="loading">
                        <span class="inline-flex items-center gap-2">
                            <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            Procesando…
                        </span>
                    </template>
                </button>

                <p class="text-xs text-zinc-500 text-center">
                    Al reservar, tu cantidad solicitada queda apartada en stock por 24 horas.
                </p>
            </form>
        </div>

        {{-- Resumen pedido sticky --}}
        <aside class="lg:sticky lg:top-20 self-start">
            <div class="bg-white rounded-2xl border border-zinc-200 overflow-hidden">
                <header class="px-5 py-4 border-b border-zinc-100">
                    <h2 class="font-semibold text-zinc-900">Tu pedido</h2>
                    <p class="text-xs text-zinc-500" x-text="`${$store.cart.count()} ${$store.cart.count() === 1 ? 'artículo' : 'artículos'}`"></p>
                </header>

                <div class="max-h-96 overflow-y-auto px-5 py-3 space-y-3 border-b border-zinc-100">
                    <template x-for="item in $store.cart.items" :key="item.id">
                        <div class="flex gap-3 items-start">
                            <img :src="item.image" :alt="item.name"
                                 onerror="if(!this.dataset.fallback){this.dataset.fallback='1';this.src='{{ asset('images/placeholder-product.svg') }}';}"
                                 class="w-14 h-14 object-cover rounded-lg bg-zinc-100 shrink-0">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-zinc-900 line-clamp-2" x-text="item.name"></p>
                                <p class="text-xs text-zinc-500" x-text="`${item.qty} × {{ $currencySymbol }} ${(item.price/100).toFixed(2)}`"></p>
                            </div>
                            <div class="text-sm font-semibold shrink-0" :style="`color: var(--shop-primary)`">
                                {{ $currencySymbol }} <span x-text="((item.price * item.qty)/100).toFixed(2)"></span>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="px-5 py-4 space-y-2">
                    <div class="flex items-center justify-between text-sm text-zinc-600">
                        <span>Subtotal</span>
                        <span x-text="`{{ $currencySymbol }} ${$store.cart.totalFormatted()}`"></span>
                    </div>
                    <div class="flex items-center justify-between text-sm text-zinc-600">
                        <span>Envío</span>
                        <span>A coordinar</span>
                    </div>
                    <div class="flex items-center justify-between pt-2 border-t border-zinc-100">
                        <span class="font-semibold text-zinc-900">Total estimado</span>
                        <span class="text-xl font-bold" :style="`color: var(--shop-primary)`">
                            {{ $currencySymbol }} <span x-text="$store.cart.totalFormatted()"></span>
                        </span>
                    </div>
                </div>
            </div>

            <a href="{{ route('shop.catalog') }}" class="block text-center mt-4 text-sm text-zinc-500 hover:text-zinc-900">
                ← Seguir comprando
            </a>
        </aside>
    </div>
</div>

<script>
    function checkoutForm() {
        return {
            buyerName: '',
            buyerPhone: '',
            loading: false,
            errorMessage: '',

            async submit() {
                this.errorMessage = '';
                if (this.$store.cart.items.length === 0) {
                    this.errorMessage = 'Tu carrito está vacío.';
                    return;
                }

                this.loading = true;
                try {
                    const csrf = document.querySelector('meta[name="csrf-token"]').content;
                    const payload = {
                        buyer_name: this.buyerName.trim(),
                        buyer_phone: this.buyerPhone.trim(),
                        items: this.$store.cart.items.map(i => ({
                            product_id: i.id,
                            qty: i.qty,
                        })),
                    };

                    const r = await fetch('{{ route('shop.reservar') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify(payload),
                    });

                    const data = await r.json();

                    if (!r.ok || !data.ok) {
                        this.errorMessage = data.error || 'No pudimos procesar tu reserva.';
                        this.loading = false;
                        return;
                    }

                    // Vaciar carrito + abrir WhatsApp.
                    this.$store.cart.clear();
                    // Pequeño delay para que el cliente alcance a ver el mensaje "Procesando" antes de saltar a WhatsApp.
                    setTimeout(() => {
                        window.location.href = data.whatsapp_url;
                    }, 200);
                } catch (e) {
                    this.errorMessage = 'Error de red. Verifica tu conexión e intenta de nuevo.';
                    this.loading = false;
                }
            },
        };
    }
</script>
@endsection
