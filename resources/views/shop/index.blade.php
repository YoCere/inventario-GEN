@extends('shop.layouts.app')

@php
    use App\Models\Setting;
    $welcomeMessage = Setting::get('shop_welcome_message');
    $currencySymbol = Setting::get('shop_currency_symbol', 'Bs.');
@endphp

@section('title', 'Catálogo')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    @if($welcomeMessage)
        <div class="mb-8 rounded-2xl p-6 md:p-8"
             style="background: linear-gradient(135deg, var(--shop-primary), var(--shop-secondary)); color: var(--shop-text-on-primary)">
            <p class="text-lg md:text-xl font-medium">{{ $welcomeMessage }}</p>
        </div>
    @endif

    <div class="grid lg:grid-cols-[260px_1fr] gap-8">

        {{-- Sidebar filtros (desktop) --}}
        <aside class="hidden lg:block space-y-6">
            <form method="GET" action="{{ route('shop.catalog') }}" id="filter-form" class="space-y-6 sticky top-20">

                {{-- Filtro categorías --}}
                <div class="bg-white rounded-2xl border border-zinc-200 p-5">
                    <h3 class="font-semibold text-zinc-900 mb-3">Categorías</h3>
                    <div class="space-y-2 max-h-72 overflow-y-auto">
                        <label class="flex items-center gap-2 cursor-pointer group">
                            <input type="radio" name="category" value=""
                                   {{ !$selectedCategory ? 'checked' : '' }}
                                   onchange="this.form.submit()"
                                   class="text-zinc-900 focus:ring-zinc-900">
                            <span class="text-sm group-hover:text-zinc-900 transition-colors {{ !$selectedCategory ? 'font-semibold text-zinc-900' : 'text-zinc-600' }}">
                                Todas
                            </span>
                        </label>
                        @foreach($categories as $cat)
                            <label class="flex items-center gap-2 cursor-pointer group">
                                <input type="radio" name="category" value="{{ $cat->id }}"
                                       {{ $selectedCategory == $cat->id ? 'checked' : '' }}
                                       onchange="this.form.submit()"
                                       class="text-zinc-900 focus:ring-zinc-900">
                                <span class="text-sm group-hover:text-zinc-900 transition-colors {{ $selectedCategory == $cat->id ? 'font-semibold text-zinc-900' : 'text-zinc-600' }}">
                                    {{ $cat->name }}
                                </span>
                                <span class="ml-auto text-xs text-zinc-400">{{ $cat->public_products_count }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                {{-- Filtro precio --}}
                @if($priceRange['max'] > 0)
                    <div class="bg-white rounded-2xl border border-zinc-200 p-5">
                        <h3 class="font-semibold text-zinc-900 mb-3">Rango de precio</h3>
                        <div class="space-y-3">
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-xs text-zinc-500">Mínimo</label>
                                    <div class="relative">
                                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-zinc-400">{{ $currencySymbol }}</span>
                                        <input type="number" name="min" value="{{ $selectedMin }}"
                                               min="{{ $priceRange['min'] }}" max="{{ $priceRange['max'] }}"
                                               class="w-full pl-10 pr-2 py-2 rounded-lg border border-zinc-200 text-sm">
                                    </div>
                                </div>
                                <div>
                                    <label class="text-xs text-zinc-500">Máximo</label>
                                    <div class="relative">
                                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-zinc-400">{{ $currencySymbol }}</span>
                                        <input type="number" name="max" value="{{ $selectedMax }}"
                                               min="{{ $priceRange['min'] }}" max="{{ $priceRange['max'] }}"
                                               class="w-full pl-10 pr-2 py-2 rounded-lg border border-zinc-200 text-sm">
                                    </div>
                                </div>
                            </div>
                            <p class="text-xs text-zinc-400">
                                Rango disponible: {{ $currencySymbol }} {{ $priceRange['min'] }} – {{ $currencySymbol }} {{ $priceRange['max'] }}
                            </p>
                            <button type="submit" class="shop-btn-secondary w-full">Aplicar precio</button>
                        </div>
                    </div>
                @endif

                {{-- Reset filtros --}}
                @if($selectedCategory || $selectedMin || $selectedMax || $selectedSort !== 'newest')
                    <a href="{{ route('shop.catalog') }}" class="block text-center text-sm text-zinc-500 hover:text-zinc-900 underline">
                        Limpiar filtros
                    </a>
                @endif

                {{-- Preserve sort en submit --}}
                <input type="hidden" name="sort" value="{{ $selectedSort }}">
            </form>
        </aside>

        {{-- Main: header + grid productos --}}
        <div>
            {{-- Toolbar móvil + sort --}}
            <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
                <p class="text-sm text-zinc-600">
                    {{ $products->total() }} {{ $products->total() === 1 ? 'producto' : 'productos' }}
                </p>

                <div class="flex items-center gap-2">
                    {{-- Mobile filters button --}}
                    <button x-data @click="$dispatch('open-mobile-filters')"
                            class="lg:hidden inline-flex items-center gap-1.5 px-3 py-2 text-sm rounded-lg border border-zinc-200 bg-white">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>
                        Filtros
                    </button>

                    <form method="GET" id="sort-form" class="inline-flex">
                        @if($selectedCategory) <input type="hidden" name="category" value="{{ $selectedCategory }}"> @endif
                        @if($selectedMin) <input type="hidden" name="min" value="{{ $selectedMin }}"> @endif
                        @if($selectedMax) <input type="hidden" name="max" value="{{ $selectedMax }}"> @endif
                        <select name="sort" onchange="this.form.submit()"
                                class="px-3 py-2 text-sm rounded-lg border border-zinc-200 bg-white">
                            <option value="newest" {{ $selectedSort === 'newest' ? 'selected' : '' }}>Más recientes</option>
                            <option value="price_asc" {{ $selectedSort === 'price_asc' ? 'selected' : '' }}>Precio: menor a mayor</option>
                            <option value="price_desc" {{ $selectedSort === 'price_desc' ? 'selected' : '' }}>Precio: mayor a menor</option>
                            <option value="name" {{ $selectedSort === 'name' ? 'selected' : '' }}>Nombre A-Z</option>
                        </select>
                    </form>
                </div>
            </div>

            {{-- Grid productos --}}
            @if($products->isEmpty())
                <div class="bg-white rounded-2xl border border-zinc-200 p-12 text-center">
                    <div class="text-5xl mb-3">🔍</div>
                    <h2 class="font-semibold text-zinc-900">No encontramos productos</h2>
                    <p class="text-sm text-zinc-500 mt-1">Prueba ajustando los filtros o limpia para ver todo.</p>
                </div>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
                    @foreach($products as $product)
                        @include('shop.components.product-card', ['product' => $product])
                    @endforeach
                </div>

                @if($products->hasPages())
                    <div class="mt-10">
                        {{ $products->onEachSide(1)->links() }}
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>

{{-- Mobile filters drawer --}}
<div x-data="{ open: false }"
     @open-mobile-filters.window="open = true; document.body.style.overflow = 'hidden'"
     class="lg:hidden">
    <div x-show="open" x-transition.opacity @click="open = false; document.body.style.overflow = ''" x-cloak class="fixed inset-0 bg-black/40 z-40"></div>
    <aside x-show="open" x-cloak
           x-transition:enter="transition ease-out duration-300"
           x-transition:enter-start="-translate-x-full"
           x-transition:enter-end="translate-x-0"
           x-transition:leave="transition ease-in duration-200"
           x-transition:leave-start="translate-x-0"
           x-transition:leave-end="-translate-x-full"
           class="fixed top-0 left-0 h-full w-80 bg-zinc-50 z-50 overflow-y-auto p-5">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-bold text-lg">Filtros</h2>
            <button @click="open = false; document.body.style.overflow = ''" class="p-1.5 hover:bg-zinc-200 rounded-full">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        {{-- Reusa formulario de sidebar via clone trick: duplico filtros aquí --}}
        <form method="GET" action="{{ route('shop.catalog') }}" class="space-y-6">
            <div class="bg-white rounded-2xl border border-zinc-200 p-4">
                <h3 class="font-semibold mb-3">Categorías</h3>
                <div class="space-y-2 max-h-72 overflow-y-auto">
                    <label class="flex items-center gap-2">
                        <input type="radio" name="category" value="" {{ !$selectedCategory ? 'checked' : '' }}>
                        <span class="text-sm">Todas</span>
                    </label>
                    @foreach($categories as $cat)
                        <label class="flex items-center gap-2">
                            <input type="radio" name="category" value="{{ $cat->id }}" {{ $selectedCategory == $cat->id ? 'checked' : '' }}>
                            <span class="text-sm flex-1">{{ $cat->name }}</span>
                            <span class="text-xs text-zinc-400">{{ $cat->public_products_count }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            @if($priceRange['max'] > 0)
                <div class="bg-white rounded-2xl border border-zinc-200 p-4">
                    <h3 class="font-semibold mb-3">Precio</h3>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" name="min" placeholder="Mín" value="{{ $selectedMin }}" class="px-2 py-2 rounded-lg border border-zinc-200 text-sm">
                        <input type="number" name="max" placeholder="Máx" value="{{ $selectedMax }}" class="px-2 py-2 rounded-lg border border-zinc-200 text-sm">
                    </div>
                </div>
            @endif

            <input type="hidden" name="sort" value="{{ $selectedSort }}">
            <button type="submit" class="shop-btn-primary w-full">Ver resultados</button>
            <a href="{{ route('shop.catalog') }}" class="block text-center text-sm text-zinc-500 hover:text-zinc-900">Limpiar filtros</a>
        </form>
    </aside>
</div>
@endsection
