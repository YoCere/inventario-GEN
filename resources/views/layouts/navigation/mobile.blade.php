{{-- Menú móvil. Mismos ítems que el de escritorio (App\Support\Ui\Navigation),
     con íconos y áreas de toque de 44px. --}}
@php
    // Ajustes entra al cajón como una sección más, al final.
    $mobileSections = $settings ? [...$sections, $settings] : $sections;
@endphp

<div class="block lg:hidden">
    <div class="flex items-center justify-between">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
            @if($companyLogoUrl)
                <img src="{{ $companyLogoUrl }}" alt="{{ $companyName }}" class="h-8 w-auto max-w-[120px] object-contain">
            @else
                <x-application-logo class="w-8 h-8 fill-current text-foreground" />
            @endif
        </a>

        <button @click="mobileMenuOpen = true"
                aria-label="Abrir menú"
                class="inline-flex items-center justify-center rounded-md border border-input bg-background hover:bg-accent hover:text-accent-foreground transition-colors h-11 w-11 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
            <x-heroicon-o-bars-3 class="h-5 w-5" />
        </button>
    </div>

    {{-- Fondo --}}
    <div x-show="mobileMenuOpen"
        x-transition:enter="duration-300 ease-out"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="duration-200 ease-in"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-40 bg-background/80 backdrop-blur-sm"
        style="display: none;"
        @click="mobileMenuOpen = false">
    </div>

    {{-- Cajón --}}
    <div x-show="mobileMenuOpen"
        x-transition:enter="duration-300 ease-in-out"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="duration-300 ease-in-out"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        class="fixed inset-y-0 right-0 z-50 flex h-full w-[88%] max-w-sm flex-col border-l border-border bg-background shadow-lg"
        style="display: none;"
        @click.stop>

        <div class="flex items-center justify-between border-b border-border px-4 py-3">
            <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-2">
                @if($companyLogoUrl)
                    <img src="{{ $companyLogoUrl }}" alt="{{ $companyName }}" class="h-8 w-auto max-w-[140px] object-contain">
                @else
                    <x-application-logo class="w-8 h-8 shrink-0 fill-current text-foreground" />
                    <span class="truncate text-base font-semibold">{{ $companyName }}</span>
                @endif
            </a>
            <button @click="mobileMenuOpen = false"
                    aria-label="Cerrar menú"
                    class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto px-2 py-2">
            @foreach($mobileSections as $section)
                @if($section['route'])
                    <a href="{{ $section['url'] }}"
                       class="flex min-h-[44px] items-center gap-3 rounded-lg px-2 py-2 text-base font-semibold transition-colors {{ $section['active'] ? 'bg-accent text-accent-foreground' : 'text-foreground hover:bg-muted' }}">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $section['chip'] }}">
                            <x-dynamic-component :component="'heroicon-o-' . $section['icon']" class="h-5 w-5 {{ $section['icon_color'] }}" />
                        </span>
                        <span class="flex-1 truncate">{{ $section['label'] }}</span>
                    </a>
                @else
                    <div x-data="{ expanded: {{ $section['active'] ? 'true' : 'false' }} }">
                        <button type="button"
                                @click="expanded = !expanded"
                                :aria-expanded="expanded ? 'true' : 'false'"
                                class="flex w-full min-h-[44px] items-center gap-3 rounded-lg px-2 py-2 text-left text-base font-semibold transition-colors {{ $section['active'] ? 'bg-accent text-accent-foreground' : 'text-foreground hover:bg-muted' }}">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $section['chip'] }}">
                                <x-dynamic-component :component="'heroicon-o-' . $section['icon']" class="h-5 w-5 {{ $section['icon_color'] }}" />
                            </span>
                            <span class="flex-1 truncate">{{ $section['label'] }}</span>
                            <span class="shrink-0 text-muted-foreground transition-transform duration-200" :class="expanded && 'rotate-180'">
                                <x-heroicon-o-chevron-down class="h-4 w-4" />
                            </span>
                        </button>

                        <div x-show="expanded" x-collapse style="display: none;">
                            <div class="ml-6 border-l border-border pb-1 pl-2">
                                @foreach($section['groups'] as $groupIndex => $group)
                                    @if($group['label'])
                                        <div class="px-2 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                            {{ $group['label'] }}
                                        </div>
                                    @endif
                                    @foreach($group['items'] as $item)
                                        <a href="{{ $item['url'] }}"
                                           class="flex min-h-[44px] items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium transition-colors {{ $item['active'] ? 'bg-accent text-accent-foreground' : 'text-foreground hover:bg-muted' }}">
                                            @if($item['icon'])
                                                <x-dynamic-component :component="'heroicon-o-' . $item['icon']" class="h-4 w-4 shrink-0 {{ $section['icon_color'] }}" />
                                            @endif
                                            <span class="flex-1">{{ $item['label'] }}</span>
                                            @if($item['badge'])
                                                <span class="inline-flex items-center justify-center min-w-5 h-5 px-1.5 rounded-full text-xs font-bold {{ \App\Support\Ui\Tone::badge(\App\Support\Ui\Tone::WARNING) }}">
                                                    {{ $item['badge'] }}
                                                </span>
                                            @endif
                                        </a>
                                    @endforeach
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach
        </nav>

        {{-- Cuenta --}}
        <div class="border-t border-border px-2 py-3">
            <div class="flex items-center gap-2 px-2 pb-2">
                <x-avatar :name="Auth::user()->name" />
                <span class="min-w-0 flex-1 truncate text-sm font-medium text-foreground">{{ Auth::user()->name }}</span>
            </div>

            @if($account)
                @foreach($account['groups'] as $group)
                    @foreach($group['items'] as $item)
                        <a href="{{ $item['url'] }}"
                           class="flex min-h-[44px] items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium transition-colors {{ $item['active'] ? 'bg-accent text-accent-foreground' : 'text-foreground hover:bg-muted' }}">
                            @if($item['icon'])
                                <x-dynamic-component :component="'heroicon-o-' . $item['icon']" class="h-4 w-4 shrink-0 text-muted-foreground" />
                            @endif
                            <span class="flex-1">{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                @endforeach
            @endif

            <button
                x-data="{
                    dark: document.documentElement.classList.contains('dark'),
                    toggle() {
                        this.dark = !this.dark;
                        document.documentElement.classList.toggle('dark', this.dark);
                        localStorage.setItem('theme', this.dark ? 'dark' : 'light');
                    }
                }"
                @click="toggle()"
                class="flex w-full min-h-[44px] items-center gap-2.5 rounded-lg px-2 py-2 text-sm font-medium text-foreground transition-colors hover:bg-muted"
            >
                <svg x-show="dark" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z" />
                </svg>
                <svg x-show="!dark" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3a7 7 0 109.79 9.79z" />
                </svg>
                <span class="flex-1 text-left" x-text="dark ? 'Modo claro' : 'Modo oscuro'"></span>
            </button>

            <form method="POST" action="{{ route('logout') }}" class="mt-2 px-2">
                @csrf
                <button type="submit"
                        class="inline-flex min-h-[44px] w-full items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90">
                    Salir
                </button>
            </form>
        </div>
    </div>
</div>
