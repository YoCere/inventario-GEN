<div class="space-y-4">

    {{-- Barra superior: publicar + ver tienda --}}
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-background p-4">
        <label class="flex items-center gap-2 text-sm font-medium text-foreground">
            <input type="checkbox" wire:model.live="landingEnabled" class="rounded border-input">
            Mostrar la landing en /tienda
        </label>

        <div class="flex items-center gap-2">
            <span class="text-xs text-muted-foreground">
                @if($landingEnabled)
                    Los visitantes ven esta página al entrar.
                @else
                    Los visitantes entran directo al catálogo.
                @endif
            </span>
            @if(\Illuminate\Support\Facades\Route::has('shop.index'))
                <a href="{{ route('shop.index') }}" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-1 px-3 py-2 rounded-md border border-input bg-background text-sm hover:bg-accent">
                    Ver tienda ↗
                </a>
            @endif
        </div>
    </div>

    <div class="grid lg:grid-cols-[minmax(0,340px)_1fr] gap-4">

        {{-- Columna izquierda: lista de secciones --}}
        <div class="rounded-lg border border-border bg-background">
            <div class="flex items-center justify-between border-b border-border px-4 py-3">
                <h3 class="text-sm font-semibold text-foreground">Secciones</h3>

                <div x-data="{ open: false }" class="relative">
                    <button type="button" @click="open = !open"
                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-md border border-input text-xs font-medium hover:bg-accent">
                        + Agregar
                    </button>
                    <div x-show="open" @click.away="open = false" x-cloak
                         class="absolute right-0 z-20 mt-1 w-56 rounded-md border border-border bg-background shadow-lg py-1">
                        @foreach($this->availableTypes as $type => $label)
                            <button type="button"
                                    wire:click="addSection('{{ $type }}')"
                                    @click="open = false"
                                    class="block w-full text-left px-3 py-2 text-sm hover:bg-accent">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            <ul class="divide-y divide-border">
                @forelse($this->sections as $index => $section)
                    <li wire:key="section-{{ $section->id }}"
                        class="flex items-center gap-2 px-3 py-2.5 {{ $selectedId === $section->id ? 'bg-accent/50' : '' }}">

                        <div class="flex flex-col">
                            <button type="button" wire:click="move({{ $section->id }}, 'up')"
                                    @disabled($index === 0)
                                    class="px-1 text-muted-foreground hover:text-foreground disabled:opacity-30"
                                    title="Subir">▲</button>
                            <button type="button" wire:click="move({{ $section->id }}, 'down')"
                                    @disabled($index === $this->sections->count() - 1)
                                    class="px-1 text-muted-foreground hover:text-foreground disabled:opacity-30"
                                    title="Bajar">▼</button>
                        </div>

                        <button type="button" wire:click="select({{ $section->id }})" class="flex-1 text-left min-w-0">
                            <span class="block text-sm font-medium text-foreground truncate">
                                {{ $section->data['heading'] ?? \App\Shop\Landing\SectionTypes::label($section->type) }}
                            </span>
                            <span class="block text-xs text-muted-foreground">
                                {{ \App\Shop\Landing\SectionTypes::label($section->type) }}
                            </span>
                        </button>

                        <button type="button" wire:click="toggleEnabled({{ $section->id }})"
                                class="px-1.5 text-xs {{ $section->is_enabled ? 'text-foreground' : 'text-muted-foreground line-through' }}"
                                title="{{ $section->is_enabled ? 'Ocultar sección' : 'Mostrar sección' }}">
                            {{ $section->is_enabled ? 'Visible' : 'Oculta' }}
                        </button>

                        <button type="button" wire:click="deleteSection({{ $section->id }})"
                                wire:confirm="¿Eliminar esta sección? También se borran sus imágenes."
                                class="px-1.5 text-muted-foreground hover:text-red-600" title="Eliminar">✕</button>
                    </li>
                @empty
                    <li class="px-4 py-6 text-sm text-muted-foreground">
                        No hay secciones. Agregá una para empezar.
                    </li>
                @endforelse
            </ul>

            @if($this->sections->where('is_enabled', true)->isEmpty() && $landingEnabled)
                <p class="border-t border-border px-4 py-3 text-xs text-amber-600 dark:text-amber-400">
                    No hay secciones visibles: /tienda mostrará una presentación mínima.
                </p>
            @endif
        </div>

        {{-- Columna derecha: formulario de la sección seleccionada --}}
        <livewire:settings.landing-section-form />
    </div>
</div>
