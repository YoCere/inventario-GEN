<section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    @isset($data['heading'])
        <h2 class="text-3xl font-bold mb-8 text-center" style="color: var(--shop-primary)">{{ $data['heading'] }}</h2>
    @endisset
    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
        @foreach(($data['images'] ?? []) as $img)
            <img src="{{ \Illuminate\Support\Facades\Storage::url($img) }}" alt=""
                 class="rounded-xl object-cover w-full aspect-square bg-zinc-100">
        @endforeach
    </div>
</section>
