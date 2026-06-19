<div class="fixed bottom-5 right-5 z-50" x-data>
    {{-- Botón burbuja --}}
    <button type="button" wire:click="$toggle('open')"
            class="flex h-14 w-14 items-center justify-center rounded-full bg-primary text-white shadow-lg hover:opacity-90 focus:outline-none"
            aria-label="Asistente">
        <x-heroicon-o-chat-bubble-left-right class="h-7 w-7" />
    </button>

    {{-- Panel --}}
    <div x-show="$wire.open" x-cloak x-transition
         class="absolute bottom-16 right-0 flex h-[28rem] w-80 flex-col overflow-hidden rounded-xl border border-border bg-background shadow-2xl">
        <header class="flex items-center justify-between border-b border-border px-4 py-2">
            <span class="font-semibold text-foreground">Asistente</span>
            <div class="flex items-center gap-2">
                <button type="button" wire:click="clear" class="text-xs text-muted-foreground hover:underline">Limpiar</button>
                <button type="button" wire:click="$toggle('open')" aria-label="Cerrar" class="text-muted-foreground hover:text-foreground">
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>
        </header>

        <div class="flex-1 space-y-2 overflow-y-auto px-3 py-3 text-sm">
            @forelse ($bubbles as $b)
                <div class="flex {{ $b['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                    <div class="max-w-[80%] whitespace-pre-wrap rounded-lg px-3 py-2 {{ $b['role'] === 'user' ? 'bg-primary text-white' : 'bg-muted text-foreground' }}">
                        {{ $b['text'] }}
                    </div>
                </div>
            @empty
                <p class="text-center text-xs text-muted-foreground">Pregúntame cómo usar el sistema o sobre tu negocio.</p>
            @endforelse

            <div wire:loading wire:target="send" class="flex justify-start">
                <div class="rounded-lg bg-muted px-3 py-2 text-muted-foreground">escribiendo…</div>
            </div>
        </div>

        <form wire:submit="send" class="flex items-center gap-2 border-t border-border p-2">
            <input type="text" wire:model="draft" wire:loading.attr="disabled" wire:target="send"
                   placeholder="Escribe tu pregunta…"
                   class="flex-1 rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none" />
            <button type="submit" wire:loading.attr="disabled" wire:target="send"
                    class="rounded-md bg-primary px-3 py-2 text-white hover:opacity-90 disabled:opacity-50">
                <x-heroicon-o-paper-airplane class="h-5 w-5" />
            </button>
        </form>
    </div>
</div>
