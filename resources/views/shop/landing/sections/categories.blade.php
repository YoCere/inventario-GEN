@php
    $auto = ($data['source'] ?? 'auto') === 'auto';
    $cats = $auto ? ($shopCategories ?? collect()) : collect($data['items'] ?? []);
@endphp
<section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    @isset($data['heading'])
        <h2 class="text-3xl font-bold mb-8 text-center" style="color: var(--shop-primary)">{{ $data['heading'] }}</h2>
    @endisset
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        @if($auto)
            @foreach($cats as $cat)
                <a href="{{ route('shop.catalog', ['category' => $cat->id]) }}"
                   class="rounded-2xl border border-zinc-200 bg-white p-6 text-center hover:shadow-md transition-shadow">
                    <p class="font-semibold text-zinc-800">{{ $cat->name }}</p>
                    <p class="text-xs text-zinc-500 mt-1">{{ $cat->public_products_count }} productos</p>
                </a>
            @endforeach
        @else
            @foreach($cats as $item)
                <a href="{{ $item['link'] ?? route('shop.catalog') }}"
                   class="rounded-2xl border border-zinc-200 bg-white p-6 text-center hover:shadow-md transition-shadow">
                    <p class="font-semibold text-zinc-800">{{ $item['label'] ?? '' }}</p>
                </a>
            @endforeach
        @endif
    </div>
</section>
