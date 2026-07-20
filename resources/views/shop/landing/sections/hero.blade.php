@php
    $ctaUrl = \App\Shop\Landing\LandingUrl::target($data['cta_target'] ?? 'catalog');
    $bgPath = \App\Shop\Landing\LandingUrl::safeStoragePath($data['background_image_path'] ?? null);
    $bg = $bgPath ? \Illuminate\Support\Facades\Storage::url($bgPath) : null;
@endphp
<section class="relative overflow-hidden"
         style="background: {{ $bg ? 'url('.\Illuminate\Support\Facades\Storage::url($bg).') center/cover' : 'linear-gradient(135deg, var(--shop-primary), var(--shop-secondary))' }}; color: var(--shop-text-on-primary)">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-20 md:py-28 text-center">
        <h1 class="text-4xl md:text-6xl font-extrabold tracking-tight">{{ $data['heading'] ?? '' }}</h1>
        @isset($data['subheading'])
            <p class="mt-4 text-lg md:text-2xl opacity-90 max-w-2xl mx-auto">{{ $data['subheading'] }}</p>
        @endisset
        @if(!empty($data['cta_text']))
            <a href="{{ $ctaUrl }}"
               class="inline-block mt-8 px-8 py-3 rounded-full font-semibold text-base shadow-lg transition-transform hover:scale-105"
               style="background-color: var(--shop-text-on-primary); color: var(--shop-primary)">
                {{ $data['cta_text'] }}
            </a>
        @endif
    </div>
</section>
