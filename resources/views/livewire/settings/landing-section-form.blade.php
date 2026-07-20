<div class="rounded-lg border border-border bg-background">
    @if(! $sectionId)
        <div class="p-8 text-center">
            <p class="text-sm text-muted-foreground">Elegí una sección de la izquierda para editarla.</p>
        </div>
    @else
        <div class="flex items-center justify-between border-b border-border px-4 py-3">
            <h3 class="text-sm font-semibold text-foreground">
                Editando: {{ \App\Shop\Landing\SectionTypes::label($type) }}
            </h3>
            <div class="flex items-center gap-2">
                <span wire:loading wire:target="save" class="text-xs text-muted-foreground">Guardando…</span>
                <x-primary-button type="button" wire:click="save">Guardar</x-primary-button>
            </div>
        </div>

        <div class="p-4">
            @include(\App\Shop\Landing\SectionTypes::form($type))
        </div>
    @endif
</div>
