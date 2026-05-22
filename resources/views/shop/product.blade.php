@extends('shop.layouts.app')

@php
    use App\Models\Setting;
    use Illuminate\Support\Facades\Storage;
    $currencySymbol = Setting::get('shop_currency_symbol', 'Bs.');

    $gallery = $product->images->isNotEmpty()
        ? $product->images
        : collect([(object)[
            'path' => $product->image_path ?: null,
            'path_card' => null,
            'path_full' => null,
            'path_thumb' => null,
            'alt_text' => $product->name,
        ]]);

    $primary = $product->primaryImage ?? $gallery->first();
    $primaryFull = $primary && ($primary->path_full ?? null) ? Storage::url($primary->path_full) : $product->card_image_url;
@endphp

@section('title', $product->name)

@push('head')
    <meta property="og:title" content="{{ $product->name }}">
    <meta property="og:image" content="{{ $primaryFull }}">
    <meta property="og:description" content="{{ Str::limit(strip_tags($product->description ?? ''), 160) }}">
@endpush

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    {{-- Breadcrumb --}}
    <nav class="text-sm text-zinc-500 mb-6">
        <a href="{{ route('shop.index') }}" class="hover:text-zinc-900">Inicio</a>
        @if($product->category)
            <span class="mx-1.5">›</span>
            <a href="{{ route('shop.index', ['category' => $product->category->id]) }}" class="hover:text-zinc-900">{{ $product->category->name }}</a>
        @endif
        <span class="mx-1.5">›</span>
        <span class="text-zinc-900">{{ $product->name }}</span>
    </nav>

    <div class="grid lg:grid-cols-2 gap-8 xl:gap-12"
         x-data="{
            activeIdx: 0,
            images: @js($gallery->map(fn($i) => [
                'full' => ($i->path_full ?? null) ? Storage::url($i->path_full) : ($i->path ? Storage::url($i->path) : asset('images/placeholder-product.svg')),
                'thumb' => ($i->path_thumb ?? null) ? Storage::url($i->path_thumb) : (($i->path_card ?? null) ? Storage::url($i->path_card) : (($i->path ?? null) ? Storage::url($i->path) : asset('images/placeholder-product.svg'))),
                'alt' => $i->alt_text ?? $product->name,
            ])->values()),
         }">

        {{-- Galería --}}
        <div>
            <div class="aspect-square rounded-2xl overflow-hidden bg-white border border-zinc-200">
                <img :src="images[activeIdx].full" :alt="images[activeIdx].alt"
                     onerror="if(!this.dataset.fallback){this.dataset.fallback='1';this.src='{{ asset('images/placeholder-product.svg') }}';}"
                     class="w-full h-full object-contain">
            </div>
            <template x-if="images.length > 1">
                <div class="mt-3 grid grid-cols-5 gap-2">
                    <template x-for="(img, idx) in images" :key="idx">
                        <button type="button" @click="activeIdx = idx"
                                :class="activeIdx === idx ? 'ring-2' : 'opacity-60 hover:opacity-100'"
                                :style="activeIdx === idx ? `--tw-ring-color: var(--shop-primary)` : ''"
                                class="aspect-square rounded-lg overflow-hidden border border-zinc-200 bg-white transition-all">
                            <img :src="img.thumb" :alt="img.alt"
                                 onerror="if(!this.dataset.fallback){this.dataset.fallback='1';this.src='{{ asset('images/placeholder-product.svg') }}';}"
                                 class="w-full h-full object-cover">
                        </button>
                    </template>
                </div>
            </template>
        </div>

        {{-- Info + CTA --}}
        <div class="space-y-5">
            @if($product->category)
                <p class="text-xs uppercase tracking-wider text-zinc-500">{{ $product->category->name }}</p>
            @endif
            <h1 class="text-3xl font-bold text-zinc-900 leading-tight">{{ $product->name }}</h1>

            <div class="flex items-baseline gap-3">
                <span class="text-3xl font-bold" style="color: var(--shop-primary)">
                    {{ $currencySymbol }} {{ number_format($product->selling_price / 100, 2) }}
                </span>
                @if($product->featured)
                    <span class="shop-badge-accent">⭐ Destacado</span>
                @endif
            </div>

            @if($product->quantity > 0)
                <p class="text-sm text-green-700 flex items-center gap-1.5">
                    <span class="inline-block w-2 h-2 rounded-full bg-green-600"></span>
                    Disponible — {{ $product->quantity }} en stock
                </p>
            @else
                <p class="text-sm text-zinc-500 flex items-center gap-1.5">
                    <span class="inline-block w-2 h-2 rounded-full bg-zinc-400"></span>
                    Sin stock por ahora
                </p>
            @endif

            @if($product->description)
                <div class="prose prose-sm max-w-none text-zinc-700 leading-relaxed">
                    {!! nl2br(e($product->description)) !!}
                </div>
            @endif

            {{-- Add to cart --}}
            <div x-data="{
                qty: 1,
                addToCart() {
                    for (let i = 0; i < this.qty; i++) {
                        $store.cart.add({
                            id: {{ $product->id }},
                            name: @js($product->name),
                            slug: @js($product->slug),
                            price_cents: {{ $product->selling_price }},
                            image: @js($product->card_image_url),
                        });
                    }
                    $store.cart.open = true;
                    document.body.style.overflow = 'hidden';
                }
            }" class="pt-3">
                @if($product->quantity > 0)
                    <div class="flex items-center gap-3 mb-3">
                        <label class="text-sm text-zinc-600">Cantidad:</label>
                        <div class="inline-flex items-center border border-zinc-200 rounded-lg bg-white">
                            <button @click="qty = Math.max(1, qty - 1)" class="w-9 h-9 hover:bg-zinc-50 rounded-l-lg">−</button>
                            <input type="number" x-model.number="qty" min="1" max="{{ $product->quantity }}"
                                   class="w-12 text-center text-sm font-medium border-0 focus:ring-0">
                            <button @click="qty = Math.min({{ $product->quantity }}, qty + 1)" class="w-9 h-9 hover:bg-zinc-50 rounded-r-lg">+</button>
                        </div>
                    </div>
                    <button @click="addToCart()" class="shop-btn-primary w-full sm:w-auto py-3 px-8 text-base">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                        Añadir al carrito
                    </button>
                @else
                    <button disabled class="shop-btn-primary w-full sm:w-auto py-3 px-8 text-base opacity-50 cursor-not-allowed">
                        Sin stock
                    </button>
                @endif
            </div>

            {{-- SKU --}}
            <p class="text-xs text-zinc-400 pt-3 border-t border-zinc-100">SKU: {{ $product->sku }}</p>
        </div>
    </div>

    {{-- Productos relacionados --}}
    @if($related->isNotEmpty())
        <section class="mt-16">
            <h2 class="text-xl font-bold mb-5 text-zinc-900">También te puede interesar</h2>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-5">
                @foreach($related as $rel)
                    @include('shop.components.product-card', ['product' => $rel])
                @endforeach
            </div>
        </section>
    @endif

</div>
@endsection
