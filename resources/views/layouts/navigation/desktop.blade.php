{{-- Menú de escritorio. Los ítems salen de App\Support\Ui\Navigation. --}}
<nav class="hidden items-center justify-between lg:flex">
    <div class="flex min-w-0 items-center gap-3 xl:gap-6">
        <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-2">
            @if($companyLogoUrl)
                <img src="{{ $companyLogoUrl }}"
                     alt="{{ $companyName }}"
                     class="h-8 w-auto max-w-[160px] object-contain">
            @else
                <x-application-logo class="w-8 h-8 fill-current text-foreground" />
                <span class="truncate text-md font-semibold tracking-tighter text-foreground">
                    {{ $companyName }}
                </span>
            @endif
        </a>

        <div class="flex flex-row items-center gap-1">
            @foreach($sections as $section)
                @if($section['route'])
                    {{-- Sección-enlace: Inicio. --}}
                    <a href="{{ $section['url'] }}"
                       class="group inline-flex h-10 w-max items-center justify-center rounded-md px-3 py-2 text-sm font-medium transition-colors hover:bg-muted xl:px-4 hover:text-accent-foreground {{ $section['active'] ? 'bg-accent/50 text-accent-foreground' : 'bg-background' }}">
                        <x-dynamic-component :component="'heroicon-o-' . $section['icon']" class="mr-2 h-4 w-4 {{ $section['icon_color'] }}" />
                        {{ $section['label'] }}
                    </a>
                @else
                    <x-nav-dropdown :active="$section['active']">
                        <x-slot name="icon">
                            <x-dynamic-component :component="'heroicon-o-' . $section['icon']" class="mr-2 h-4 w-4 {{ $section['icon_color'] }}" />
                        </x-slot>
                        <x-slot name="trigger">{{ $section['label'] }}</x-slot>
                        <x-slot name="content">
                            @include('layouts.navigation.dropdown-items', ['section' => $section])
                        </x-slot>
                    </x-nav-dropdown>
                @endif
            @endforeach
        </div>
    </div>

    <div class="flex shrink-0 items-center gap-2">
        {{-- Ajustes: botón propio, no enterrado bajo el avatar. --}}
        @if($settings)
            <x-dropdown align="right" width="w-60">
                <x-slot name="trigger">
                    <button type="button"
                            title="{{ $settings['label'] }}"
                            aria-label="{{ $settings['label'] }}"
                            class="inline-flex h-9 items-center justify-center gap-1.5 rounded-md border border-input px-2.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring {{ $settings['active'] ? 'bg-accent text-accent-foreground' : 'bg-background hover:bg-accent hover:text-accent-foreground' }}">
                        <x-dynamic-component :component="'heroicon-o-' . $settings['icon']" class="h-4 w-4" />
                        <span class="hidden xl:inline">{{ $settings['label'] }}</span>
                    </button>
                </x-slot>
                <x-slot name="content">
                    <div class="flex flex-col p-1">
                        @include('layouts.navigation.dropdown-items', ['section' => $settings])
                    </div>
                </x-slot>
            </x-dropdown>
        @endif

        {{-- Toggle modo oscuro/claro --}}
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
            :title="dark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'"
            :aria-label="dark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'"
            class="inline-flex items-center justify-center rounded-md h-9 w-9 border border-input bg-background hover:bg-accent hover:text-accent-foreground transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
            <svg x-show="dark" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z" />
            </svg>
            <svg x-show="!dark" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3a7 7 0 109.79 9.79z" />
            </svg>
        </button>

        <x-dropdown align="right" width="w-48">
            <x-slot name="trigger">
                <button class="inline-flex items-center justify-center whitespace-nowrap rounded-full text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 gap-2">
                    {{-- Debajo de xl el nombre se va: si no, el menú no entra en 1024px. --}}
                    <span class="hidden xl:inline-flex">{{ Auth::user()->name }}</span>
                    <x-avatar :name="Auth::user()->name" />
                </button>
            </x-slot>

            <x-slot name="content">
                @if($account)
                    @foreach($account['groups'] as $group)
                        @foreach($group['items'] as $item)
                            <x-dropdown-link :href="$item['url']" :active="$item['active']">
                                {{ $item['label'] }}
                            </x-dropdown-link>
                        @endforeach
                    @endforeach
                @endif

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-dropdown-link :href="route('logout')"
                            onclick="event.preventDefault(); this.closest('form').submit();">
                        Salir
                    </x-dropdown-link>
                </form>
            </x-slot>
        </x-dropdown>
    </div>
</nav>
