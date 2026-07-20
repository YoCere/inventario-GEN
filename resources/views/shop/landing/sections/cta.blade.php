@php
    $ctaUrl = match($data['target'] ?? 'catalog') {
        'catalog' => route('shop.catalog'),
        'whatsapp' => 'https://wa.me/' . preg_replace('/\D/', '', (string) \App\Models\Setting::get('shop_whatsapp_number', '')),
        default => $data['target'],
    };
@endphp
<section class="py-16" style="background: linear-gradient(135deg, var(--shop-primary), var(--shop-secondary)); color: var(--shop-text-on-primary)">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        @isset($data['heading'])
            <h2 class="text-3xl md:text-4xl font-bold">{{ $data['heading'] }}</h2>
        @endisset
        @if(!empty($data['text']))
            <p class="mt-3 text-lg opacity-90">{{ $data['text'] }}</p>
        @endif
        <a href="{{ $ctaUrl }}"
           class="inline-block mt-8 px-8 py-3 rounded-full font-semibold text-base shadow-lg transition-transform hover:scale-105"
           style="background-color: var(--shop-text-on-primary); color: var(--shop-primary)">
            {{ $data['button_text'] ?? 'Entrar a la tienda' }}
        </a>
    </div>
</section>
