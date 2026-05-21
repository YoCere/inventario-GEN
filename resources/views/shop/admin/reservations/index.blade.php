<x-app-layout>
    <x-slot name="header">
        <h2 class="text-2xl font-semibold text-foreground">Reservas Web</h2>
    </x-slot>

    @php
        use App\Models\Setting;
        $currencySymbol = Setting::get('currency_symbol', 'Bs');
    @endphp

    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8 space-y-6">

        @if(session('success'))
            <div class="bg-green-50 border border-green-200 text-green-800 rounded-md p-3 text-sm">
                {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="bg-red-50 border border-red-200 text-red-800 rounded-md p-3 text-sm">
                {{ session('error') }}
            </div>
        @endif

        {{-- Counters --}}
        <div class="grid grid-cols-3 gap-4">
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs uppercase text-muted-foreground">Pendientes</p>
                <p class="text-3xl font-bold mt-1 text-amber-600">{{ $counts['pending'] }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs uppercase text-muted-foreground">Completadas</p>
                <p class="text-3xl font-bold mt-1 text-green-600">{{ $counts['completed'] }}</p>
            </div>
            <div class="rounded-xl border border-border bg-card p-4">
                <p class="text-xs uppercase text-muted-foreground">Canceladas</p>
                <p class="text-3xl font-bold mt-1 text-zinc-500">{{ $counts['cancelled'] }}</p>
            </div>
        </div>

        @php
            $renderRow = function ($sale) use ($currencySymbol, $waBuilder) {
                return ['sale' => $sale, 'currency' => $currencySymbol, 'wa' => $waBuilder];
            };
        @endphp

        {{-- Pending (priority section) --}}
        <section class="rounded-xl border border-amber-200 bg-amber-50/30">
            <header class="px-5 py-3 border-b border-amber-200 flex items-center justify-between">
                <h3 class="font-semibold text-amber-900 flex items-center gap-2">
                    <span class="inline-block w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                    Pendientes de confirmar
                </h3>
                <span class="text-xs text-amber-700">{{ $pending->count() }}</span>
            </header>
            <div class="divide-y divide-amber-100">
                @forelse($pending as $sale)
                    @include('shop.admin.reservations._row', ['sale' => $sale, 'currency' => $currencySymbol, 'wa' => $waBuilder, 'showActions' => true])
                @empty
                    <p class="px-5 py-8 text-center text-sm text-muted-foreground">No hay reservas pendientes 🎉</p>
                @endforelse
            </div>
        </section>

        {{-- Completed --}}
        @if($completed->isNotEmpty())
            <section class="rounded-xl border border-border bg-card">
                <header class="px-5 py-3 border-b border-border">
                    <h3 class="font-semibold text-foreground">Completadas (últimas {{ $completed->count() }})</h3>
                </header>
                <div class="divide-y divide-border">
                    @foreach($completed as $sale)
                        @include('shop.admin.reservations._row', ['sale' => $sale, 'currency' => $currencySymbol, 'wa' => $waBuilder, 'showActions' => false])
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Cancelled --}}
        @if($cancelled->isNotEmpty())
            <section class="rounded-xl border border-border bg-card opacity-75">
                <header class="px-5 py-3 border-b border-border">
                    <h3 class="font-semibold text-foreground">Canceladas (últimas {{ $cancelled->count() }})</h3>
                </header>
                <div class="divide-y divide-border">
                    @foreach($cancelled as $sale)
                        @include('shop.admin.reservations._row', ['sale' => $sale, 'currency' => $currencySymbol, 'wa' => $waBuilder, 'showActions' => false])
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-app-layout>
