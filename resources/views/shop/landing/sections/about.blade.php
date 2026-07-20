@php
    $clean = app(\App\Shop\Services\LandingHtmlSanitizer::class)->sanitize($data['body_html'] ?? '');
    $img = $data['image_path'] ?? null;
@endphp
<section class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    <div class="grid md:grid-cols-[1fr_auto] gap-8 items-center">
        <div>
            @isset($data['heading'])
                <h2 class="text-3xl font-bold mb-4" style="color: var(--shop-primary)">{{ $data['heading'] }}</h2>
            @endisset
            <div class="prose max-w-none text-zinc-700 leading-relaxed">{!! $clean !!}</div>
        </div>
        @if($img)
            <img src="{{ \Illuminate\Support\Facades\Storage::url($img) }}" alt="{{ $data['heading'] ?? '' }}"
                 class="rounded-2xl shadow-lg w-full max-w-sm object-cover">
        @endif
    </div>
</section>
