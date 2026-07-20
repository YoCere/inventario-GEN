@php
    use App\Models\Setting;
    use Illuminate\Support\Facades\Storage;

    $businessName = Setting::get('shop_business_name') ?: config('app.name');
    $logoPath = Setting::get('shop_logo_path');
    $logoUrl = $logoPath ? Storage::url($logoPath) : null;
    $primaryColor = Setting::get('shop_primary_color', '#2563EB');
    $secondaryColor = Setting::get('shop_secondary_color', '#64748B');
    $accentColor = Setting::get('shop_accent_color', '#F59E0B');
    $textOnPrimary = Setting::get('shop_text_on_primary', '#FFFFFF');
    $currencySymbol = Setting::get('shop_currency_symbol', 'Bs.');
@endphp
<!DOCTYPE html>
<html lang="es" class="antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', $businessName)</title>

    @include('shop.partials.share-meta', ['meta' => $shareMeta ?? null, 'siteName' => $businessName])

    @if($logoUrl)
        <link rel="icon" href="{{ $logoUrl }}">
    @endif

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet">

    @vite(['resources/css/shop/shop.css', 'resources/js/shop/shop.js'])

    <style>
        :root {
            --shop-primary: {{ $primaryColor }};
            --shop-secondary: {{ $secondaryColor }};
            --shop-accent: {{ $accentColor }};
            --shop-text-on-primary: {{ $textOnPrimary }};
        }
        body { font-family: 'Inter', system-ui, sans-serif; }
    </style>

    @stack('head')
</head>
<body class="bg-zinc-50 text-zinc-900 min-h-screen flex flex-col">

    {{-- Header sticky --}}
    <header class="sticky top-0 z-30 backdrop-blur-md bg-white/90 border-b border-zinc-200/80"
            x-data>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center gap-4 h-16">

                {{-- Logo + nombre --}}
                <a href="{{ route('shop.index') }}" class="flex items-center gap-2 shrink-0">
                    @if($logoUrl)
                        <img src="{{ $logoUrl }}" alt="{{ $businessName }}" class="h-9 w-9 object-contain rounded-lg">
                    @else
                        <div class="h-9 w-9 rounded-lg flex items-center justify-center font-bold text-sm"
                             style="background-color: var(--shop-primary); color: var(--shop-text-on-primary)">
                            {{ mb_substr($businessName, 0, 1) }}
                        </div>
                    @endif
                    <span class="font-bold text-lg text-zinc-900 hidden sm:inline">{{ $businessName }}</span>
                </a>

                {{-- Buscador inteligente --}}
                <div class="flex-1 max-w-2xl mx-auto relative"
                     x-data
                     @click.away="$store.search.open = false">
                    <div class="relative">
                        <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-3 top-1/2 -translate-y-1/2 h-5 w-5 text-zinc-400 pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"/>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                        <input type="text"
                               :value="$store.search.query"
                               @input="$store.search.onInput($event.target.value)"
                               @focus="if($store.search.results.length) $store.search.open = true"
                               placeholder="Busca productos… (tolera errores de escritura)"
                               class="w-full pl-10 pr-10 py-2.5 rounded-full border border-zinc-200 bg-zinc-50 focus:bg-white text-sm placeholder:text-zinc-400 focus:outline-none focus:ring-2 focus:border-transparent transition-all"
                               style="--tw-ring-color: var(--shop-primary)">
                        <button x-show="$store.search.query"
                                @click="$store.search.clear()"
                                x-cloak
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-600">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>

                    {{-- Dropdown resultados --}}
                    <div x-show="$store.search.open"
                         x-transition.opacity
                         x-cloak
                         class="absolute top-full left-0 right-0 mt-2 bg-white rounded-xl shadow-2xl border border-zinc-200 overflow-hidden max-h-96 overflow-y-auto z-40">
                        <template x-if="$store.search.loading">
                            <div class="p-4 text-center text-sm text-zinc-500">Buscando…</div>
                        </template>
                        <template x-if="!$store.search.loading && $store.search.results.length === 0 && $store.search.query.length >= 2">
                            <div class="p-6 text-center text-sm text-zinc-500">
                                No encontramos "<span x-text="$store.search.query"></span>".
                                <br><span class="text-xs">Prueba con menos palabras.</span>
                            </div>
                        </template>
                        <template x-for="r in $store.search.results" :key="r.id">
                            <a :href="r.url" class="flex items-center gap-3 p-3 hover:bg-zinc-50 transition-colors border-b border-zinc-100 last:border-b-0">
                                <img :src="r.image" :alt="r.name"
                                     onerror="if(!this.dataset.fallback){this.dataset.fallback='1';this.src='{{ asset('images/placeholder-product.svg') }}';}"
                                     class="w-12 h-12 object-cover rounded-lg bg-zinc-100">
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-sm text-zinc-900 truncate" x-text="r.name"></p>
                                    <p class="text-xs text-zinc-500" x-text="'SKU: ' + r.sku"></p>
                                </div>
                                <p class="font-semibold text-sm shrink-0" :style="`color: var(--shop-primary)`">
                                    {{ $currencySymbol }} <span x-text="r.price"></span>
                                </p>
                            </a>
                        </template>
                    </div>
                </div>

                {{-- Carrito icon --}}
                <button @click="$store.cart.toggle()"
                        class="relative p-2 hover:bg-zinc-100 rounded-full transition-colors shrink-0"
                        aria-label="Abrir carrito">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-zinc-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="9" cy="21" r="1"/>
                        <circle cx="20" cy="21" r="1"/>
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                    </svg>
                    <span x-show="$store.cart.count() > 0"
                          x-text="$store.cart.count()"
                          x-cloak
                          class="absolute -top-1 -right-1 min-w-5 h-5 px-1 rounded-full text-xs font-bold flex items-center justify-center"
                          style="background-color: var(--shop-primary); color: var(--shop-text-on-primary)">
                    </span>
                </button>
            </div>
        </div>
    </header>

    {{-- Flash toast (añadido al carrito) --}}
    <div x-data
         x-show="$store.cart.flash"
         x-transition.opacity
         x-cloak
         class="fixed top-20 left-1/2 -translate-x-1/2 z-50 bg-zinc-900 text-white px-5 py-2.5 rounded-full text-sm font-medium shadow-2xl"
         x-text="$store.cart.flash">
    </div>

    <main class="flex-1">
        @yield('content')
    </main>

    {{-- Cart drawer --}}
    @include('shop.components.cart-drawer')

    <footer class="mt-16 border-t border-zinc-200 bg-white py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center text-sm text-zinc-500">
            <p class="font-semibold text-zinc-700">{{ $businessName }}</p>
            <p class="mt-1">© {{ date('Y') }} — Todos los derechos reservados</p>
        </div>
    </footer>

</body>
</html>
