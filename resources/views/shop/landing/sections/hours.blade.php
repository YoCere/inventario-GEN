<section class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    @isset($data['heading'])
        <h2 class="text-3xl font-bold mb-6 text-center" style="color: var(--shop-primary)">{{ $data['heading'] }}</h2>
    @endisset
    <div class="rounded-2xl border border-zinc-200 bg-white divide-y divide-zinc-100">
        @foreach(($data['rows'] ?? []) as $row)
            <div class="flex items-center justify-between px-6 py-4">
                <span class="font-medium text-zinc-800">{{ $row['label'] ?? '' }}</span>
                <span class="text-zinc-600">{{ $row['value'] ?? '' }}</span>
            </div>
        @endforeach
    </div>
</section>
