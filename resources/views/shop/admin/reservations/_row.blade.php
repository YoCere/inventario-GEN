@php
    /** @var \App\Models\Sale $sale */
    /** @var bool $showActions */
    $phoneDigits = preg_replace('/\D+/', '', $sale->buyer_phone ?? '');
    $waContactUrl = $phoneDigits ? "https://wa.me/{$phoneDigits}" : null;
@endphp

<article class="px-5 py-4 flex flex-col md:flex-row md:items-center gap-3 hover:bg-zinc-50 transition-colors">

    {{-- Cabecera reserva --}}
    <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 flex-wrap">
            <a href="{{ route('sales.show', $sale->id) }}" class="font-semibold text-foreground hover:underline">
                {{ $sale->invoice_number }}
            </a>
            <span class="text-xs px-2 py-0.5 rounded-full
                {{ $sale->status->value === 'PENDING' ? 'bg-amber-100 text-amber-800' : '' }}
                {{ $sale->status->value === 'COMPLETED' ? 'bg-green-100 text-green-800' : '' }}
                {{ $sale->status->value === 'CANCELLED' ? 'bg-zinc-200 text-zinc-700' : '' }}">
                {{ $sale->status->label() }}
            </span>
            <span class="text-xs text-muted-foreground">
                {{ $sale->created_at->diffForHumans() }}
            </span>
        </div>

        <div class="mt-1 text-sm text-foreground flex items-center gap-3 flex-wrap">
            <span class="font-medium">👤 {{ $sale->buyer_name ?? 'Sin nombre' }}</span>
            @if($sale->buyer_phone)
                <span class="text-muted-foreground">📱 {{ $sale->buyer_phone }}</span>
            @endif
        </div>

        <p class="mt-1 text-xs text-muted-foreground line-clamp-1">
            @foreach($sale->items as $item)
                {{ $item->quantity }}× {{ $item->product?->name ?? 'Producto' }}@if(!$loop->last), @endif
            @endforeach
        </p>
    </div>

    {{-- Total --}}
    <div class="text-right shrink-0">
        <p class="text-xs text-muted-foreground">Total</p>
        <p class="text-lg font-bold text-foreground">{{ $currency }} {{ number_format($sale->total / 100, 2) }}</p>
    </div>

    {{-- Acciones --}}
    @if($showActions)
        <div class="flex items-center gap-2 shrink-0">
            @if($waContactUrl)
                <a href="{{ $waContactUrl }}" target="_blank" rel="noopener"
                   title="Abrir WhatsApp con {{ $sale->buyer_phone }}"
                   class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-green-100 text-green-700 hover:bg-green-200 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M.057 24l1.687-6.163a11.867 11.867 0 01-1.587-5.946C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 018.413 3.488 11.824 11.824 0 013.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 01-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981z"/></svg>
                </a>
            @endif

            <form method="POST" action="{{ route('shop.admin.reservations.confirm', $sale->id) }}"
                  onsubmit="return confirm('¿Confirmar reserva {{ $sale->invoice_number }} como completada y cobrada?')">
                @csrf
                <button type="submit"
                        title="Confirmar y marcar como completada"
                        class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-emerald-100 text-emerald-700 hover:bg-emerald-200 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                </button>
            </form>

            <form method="POST" action="{{ route('shop.admin.reservations.cancel', $sale->id) }}"
                  onsubmit="return confirm('¿Cancelar reserva {{ $sale->invoice_number }}? Esto restaurará el stock.')">
                @csrf
                <button type="submit"
                        title="Cancelar reserva (restaura stock)"
                        class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-red-100 text-red-700 hover:bg-red-200 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </form>
        </div>
    @endif
</article>
