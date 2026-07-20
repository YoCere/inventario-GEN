@php
    $wa = preg_replace('/\D/', '', (string) ($data['whatsapp'] ?? ''));
@endphp
<section class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-16 text-center">
    @isset($data['heading'])
        <h2 class="text-3xl font-bold mb-6" style="color: var(--shop-primary)">{{ $data['heading'] }}</h2>
    @endisset
    <div class="space-y-2 text-zinc-700">
        @if($wa)
            <p><a href="https://wa.me/{{ $wa }}" class="underline" style="color: var(--shop-primary)">WhatsApp: {{ $data['whatsapp'] }}</a></p>
        @endif
        @if(!empty($data['address']))<p>{{ $data['address'] }}</p>@endif
        @if(!empty($data['email']))<p><a href="mailto:{{ $data['email'] }}" class="underline">{{ $data['email'] }}</a></p>@endif
    </div>
</section>
