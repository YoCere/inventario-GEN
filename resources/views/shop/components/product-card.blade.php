@props(['product'])
@php
    $currencySymbol = \App\Models\Setting::get('shop_currency_symbol', 'Bs.');
    $img = $product->primaryImage;
    $cardUrl = $img && $img->path_card ? \Illuminate\Support\Facades\Storage::url($img->path_card) : $product->card_image_url;
    $fullUrl = $img && $img->path_full ? \Illuminate\Support\Facades\Storage::url($img->path_full) : $cardUrl;
    $placeholderUrl = asset('images/placeholder-product.svg');
@endphp

<article class="shop-card group" x-data="{
    addToCart() {
        $store.cart.add({
            id: {{ $product->id }},
            name: @js($product->name),
            slug: @js($product->slug),
            price_cents: {{ $product->selling_price }},
            image: @js($cardUrl),
        });
    }
}">
    <a href="{{ route('shop.product', $product->slug) }}" class="block">
        <div class="relative aspect-square overflow-hidden bg-zinc-100">
            {{-- onerror: si el archivo no existe en disk (ej. tras un deploy
                 fresco sin volume mount, o producto cuya imagen fue borrada),
                 reemplaza por placeholder en lugar de mostrar el icono roto
                 del navegador con el alt-text. Idempotente vía dataset flag. --}}
            <picture>
                <source media="(min-width: 768px)" srcset="{{ $fullUrl }}">
                <img src="{{ $cardUrl }}"
                     alt="{{ $product->name }}"
                     loading="lazy"
                     decoding="async"
                     onerror="if(!this.dataset.fallback){this.dataset.fallback='1';this.src='{{ $placeholderUrl }}';}"
                     class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
            </picture>
            @if($product->featured)
                <span class="absolute top-3 left-3 shop-badge-accent">⭐ Destacado</span>
            @endif
            @if($product->quantity <= 0)
                <div class="absolute inset-0 bg-white/70 flex items-center justify-center">
                    <span class="bg-zinc-900 text-white px-3 py-1 rounded-full text-xs font-semibold">Sin stock</span>
                </div>
            @endif
        </div>

        <div class="p-4 space-y-1">
            @if($product->category)
                <p class="text-xs text-zinc-500 uppercase tracking-wide">{{ $product->category->name }}</p>
            @endif
            <h3 class="font-semibold text-zinc-900 line-clamp-2 leading-snug">{{ $product->name }}</h3>
            <p class="text-lg font-bold pt-1" style="color: var(--shop-primary)">
                {{ $currencySymbol }} {{ number_format($product->selling_price / 100, 2) }}
            </p>
        </div>
    </a>

    <div class="px-4 pb-4">
        <button @click="addToCart()"
                @disabled($product->quantity <= 0)
                class="shop-btn-primary w-full disabled:opacity-40 disabled:cursor-not-allowed">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Añadir
        </button>
    </div>
</article>
