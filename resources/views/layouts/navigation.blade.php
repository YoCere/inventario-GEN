<section class="py-4 bg-background border-b border-border print:hidden">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8" x-data="{ mobileMenuOpen: false }">
        <!-- Desktop Menu -->
        <nav class="hidden items-center justify-between lg:flex">
            <div class="flex items-center gap-6">
                <!-- Logo empresa -->
                @php
                    $companyLogoPath = \App\Models\Setting::get('store_logo_path');
                    $companyLogoUrl  = $companyLogoPath ? \Illuminate\Support\Facades\Storage::url($companyLogoPath) : null;
                    $companyName     = \App\Models\Setting::get('store_name', config('app.name'));
                @endphp
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                    @if($companyLogoUrl)
                        <img src="{{ $companyLogoUrl }}"
                             alt="{{ $companyName }}"
                             class="h-8 w-auto max-w-[160px] object-contain">
                    @else
                        <x-application-logo class="w-8 h-8 fill-current text-foreground" />
                        <span class="text-md font-semibold tracking-tighter text-foreground">
                            {{ $companyName }}
                        </span>
                    @endif
                </a>

                <!-- Navigation Menu -->
                <div class="flex items-center">
                    <div class="flex flex-row gap-1">
                        <!-- Dashboard Link -->
                        <a href="{{ route('dashboard') }}" class="group inline-flex h-10 w-max items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors hover:bg-muted hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-50 {{ request()->routeIs('dashboard') ? 'bg-accent/50 text-accent-foreground' : 'bg-background' }}">
                            <x-heroicon-o-squares-2x2 class="mr-2 h-4 w-4" />
                            Panel
                        </a>

                        <!-- Sales Dropdown -->
                        <x-nav-dropdown active="{{ request()->routeIs(['sales.*', 'customers.*']) }}">
                            <x-slot name="icon">
                                <x-heroicon-o-banknotes class="mr-2 h-4 w-4" />
                            </x-slot>
                            <x-slot name="trigger">
                                Ventas
                            </x-slot>
                            <x-slot name="content">
                                <x-dropdown-link :href="route('sales.create')" :active="request()->routeIs('sales.create')">
                                 Vender
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('sales.index')" :active="request()->routeIs(['sales.index', 'sales.show'])">
                                    Ventas
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('customers.index')" :active="request()->routeIs('customers.*')">
                                    Clientes
                                </x-dropdown-link>
                                @if(app(\App\Shop\Services\ShopFeatureFlag::class)->enabled() && \Illuminate\Support\Facades\Route::has('shop.admin.reservations'))
                                    <div class="my-1 border-t border-border"></div>
                                    @php
                                        // Pending count cacheado 30s: invalidación natural por TTL.
                                        // Suficiente para badge informativo, no requiere tiempo real.
                                        $pendingWebReservations = \Illuminate\Support\Facades\Cache::remember(
                                            'shop.pending_web_count',
                                            30,
                                            fn () => \App\Models\Sale::where('source', 'web')
                                                ->where('status', \App\Enums\SaleStatus::PENDING)
                                                ->count()
                                        );
                                    @endphp
                                    <x-dropdown-link :href="route('shop.admin.reservations')" :active="request()->routeIs('shop.admin.*')">
                                        <span class="flex items-center justify-between w-full">
                                            <span>Reservas Web</span>
                                            @if($pendingWebReservations > 0)
                                                <span class="ml-2 inline-flex items-center justify-center min-w-5 h-5 px-1.5 rounded-full text-xs font-bold bg-amber-500 text-white">{{ $pendingWebReservations }}</span>
                                            @endif
                                        </span>
                                    </x-dropdown-link>
                                @endif
                            </x-slot>
                        </x-nav-dropdown>

                        <!-- Purchases Dropdown -->
                        <x-nav-dropdown active="{{ request()->routeIs(['purchases.*', 'suppliers.*']) }}">
                            <x-slot name="icon">
                                <x-heroicon-o-shopping-cart class="mr-2 h-4 w-4" />
                            </x-slot>
                            <x-slot name="trigger">
                                Compras
                            </x-slot>
                            <x-slot name="content">
                                <x-dropdown-link :href="route('purchases.index')" :active="request()->routeIs('purchases.*')">
                                    Compras
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('suppliers.index')" :active="request()->routeIs('suppliers.*')">
                                    Proveedores
                                </x-dropdown-link>
                            </x-slot>
                        </x-nav-dropdown>

                        <!-- Finance Dropdown -->
                        @canany(['finance.view','finance.accounting','assets.manage','loans.manage','budgets.manage','production.manage'])
                        <x-nav-dropdown active="{{ request()->routeIs(['finance.*']) }}">
                            <x-slot name="icon">
                                <x-heroicon-o-currency-dollar class="mr-2 h-4 w-4" />
                            </x-slot>
                            <x-slot name="trigger">
                                Finanzas
                            </x-slot>
                            <x-slot name="content">
                                @can('finance.view')
                                <x-dropdown-link :href="route('finance.index')" :active="request()->routeIs('finance.index')">
                                    Resumen financiero
                                </x-dropdown-link>
                                @endcan
                                @can('finance.accounting')
                                <div class="my-1 border-t border-border"></div>
                                <div class="px-2 py-1 text-xs font-semibold text-muted-foreground uppercase tracking-wide">Contabilidad</div>
                                <x-dropdown-link :href="route('finance.chart-of-accounts.index')" :active="request()->routeIs('finance.chart-of-accounts.index')">
                                    Plan de cuentas
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('finance.journal-entries.index')" :active="request()->routeIs('finance.journal-entries.index')">
                                    Libro diario
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('finance.statements.index')" :active="request()->routeIs('finance.statements.index')">
                                    Estados financieros
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('finance.trial-balance')" :active="request()->routeIs('finance.trial-balance')">
                                    Balance de Sumas y Saldos
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('finance.worksheet')" :active="request()->routeIs('finance.worksheet')">
                                    Hoja Teórica
                                </x-dropdown-link>
                                @endcan
                                @can('assets.manage')
                                <div class="my-1 border-t border-border"></div>
                                <div class="px-2 py-1 text-xs font-semibold text-muted-foreground uppercase tracking-wide">Activos Fijos</div>
                                <x-dropdown-link :href="route('finance.asset-categories.index')" :active="request()->routeIs('finance.asset-categories.index')">
                                    Categorías de Activo
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('finance.fixed-assets.index')" :active="request()->routeIs('finance.fixed-assets.*')">
                                    Activos Fijos
                                </x-dropdown-link>
                                @endcan
                                @can('loans.manage')
                                <div class="my-1 border-t border-border"></div>
                                <div class="px-2 py-1 text-xs font-semibold text-muted-foreground uppercase tracking-wide">Préstamos</div>
                                <x-dropdown-link :href="route('finance.loans.index')" :active="request()->routeIs('finance.loans.*')">
                                    Préstamos
                                </x-dropdown-link>
                                @endcan
                                @can('budgets.manage')
                                <div class="my-1 border-t border-border"></div>
                                <div class="px-2 py-1 text-xs font-semibold text-muted-foreground uppercase tracking-wide">Presupuestos</div>
                                <x-dropdown-link :href="route('finance.budgets.index')" :active="request()->routeIs('finance.budgets.*')">
                                    Presupuestos
                                </x-dropdown-link>
                                @endcan
                                @can('production.manage')
                                <div class="my-1 border-t border-border"></div>
                                <div class="px-2 py-1 text-xs font-semibold text-muted-foreground uppercase tracking-wide">Producción</div>
                                <x-dropdown-link :href="route('finance.boms.index')" :active="request()->routeIs('finance.boms.*')">
                                    Recetas (BOM)
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('finance.production.index')" :active="request()->routeIs('finance.production.*')">
                                    Producción
                                </x-dropdown-link>
                                @endcan
                                @can('finance.view')
                                <div class="my-1 border-t border-border"></div>
                                <div class="px-2 py-1 text-xs font-semibold text-muted-foreground uppercase tracking-wide">Tesorería</div>
                                <x-dropdown-link :href="route('finance.transactions.index')" :active="request()->routeIs('finance.transactions.index')">
                                    Transacciones
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('finance.categories.index')" :active="request()->routeIs('finance.categories.index')">
                                    Categorías
                                </x-dropdown-link>
                                @endcan
                            </x-slot>
                        </x-nav-dropdown>
                        @endcanany

                        <!-- Users Dropdown (Admin Only) -->
                        @if(auth()->user()->isAdmin())
                        <x-nav-dropdown active="{{ request()->routeIs('users.*') }}">
                            <x-slot name="icon">
                                <x-heroicon-o-users class="mr-2 h-4 w-4" />
                            </x-slot>
                            <x-slot name="trigger">
                                Usuarios
                            </x-slot>
                            <x-slot name="content">
                                <x-dropdown-link :href="route('users.index')" :active="request()->routeIs('users.index')">
                                    Usuarios
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('users.payroll.index')" :active="request()->routeIs('users.payroll.*')">
                                    Planilla de sueldos
                                </x-dropdown-link>
                            </x-slot>
                        </x-nav-dropdown>
                        @endif

                        <!-- Products Dropdown -->
                        <x-nav-dropdown active="{{ request()->routeIs(['products.*', 'categories.*', 'units.*']) }}">
                            <x-slot name="icon">
                                <x-heroicon-o-cube class="mr-2 h-4 w-4" />
                            </x-slot>
                            <x-slot name="trigger">
                                Productos
                            </x-slot>
                            <x-slot name="content">
                                <x-dropdown-link :href="route('products.index')" :active="request()->routeIs('products.*')">
                                    Productos
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('categories.index')" :active="request()->routeIs('categories.*')">
                                    Categorías
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('units.index')" :active="request()->routeIs('units.*')">
                                    Unidades
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('warehouses.index')" :active="request()->routeIs('warehouses.*')">
                                    Almacenes
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('locations.index')" :active="request()->routeIs('locations.*')">
                                    Ubicaciones
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('transfers.index')" :active="request()->routeIs('transfers.*')">
                                    Transferencias
                                </x-dropdown-link>
                                <x-dropdown-link :href="route('products.kardex.index')" :active="request()->routeIs('products.kardex.index')">
                                    Kardex valorizado
                                </x-dropdown-link>
                            </x-slot>
                        </x-nav-dropdown>
                    </div>
                </div>
            </div>

            <!-- User Auth Buttons -->
            <div class="flex items-center gap-2">
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
                    class="inline-flex items-center justify-center rounded-md h-9 w-9 border border-input bg-background hover:bg-accent hover:text-accent-foreground transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    {{-- Sol (visible en modo oscuro) --}}
                    <svg x-show="dark" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z" />
                    </svg>
                    {{-- Luna (visible en modo claro) --}}
                    <svg x-show="!dark" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3a7 7 0 109.79 9.79z" />
                    </svg>
                </button>

                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center justify-center whitespace-nowrap rounded-full text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 gap-2">
                            <span class="hidden md:inline-flex">{{ Auth::user()->name }}</span>
                            <x-avatar :name="Auth::user()->name" />
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.index')" :active="request()->routeIs('profile.*')">
                            Perfil
                        </x-dropdown-link>

                        @if(auth()->user()->isAdmin())
                        <x-dropdown-link :href="route('settings.index')" :active="request()->routeIs('settings.index')">
                            Ajustes
                        </x-dropdown-link>
                        @endif

                        @if(auth()->user()->isDeveloper())
                        <x-dropdown-link :href="route('settings.backups')" :active="request()->routeIs('settings.backups')">
                            <svg xmlns="http://www.w3.org/2000/svg" class="inline-block mr-1.5 h-4 w-4 align-text-bottom" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 15a4 4 0 004 4h10a4 4 0 10-.893-7.902A5 5 0 003 15z" />
                            </svg>
                            Backups
                        </x-dropdown-link>
                        @endif

                        @if(auth()->user()->isDeveloper())
                        <x-dropdown-link :href="route('roles.index')" :active="request()->routeIs('roles.*')">
                            🔧 Roles y permisos
                        </x-dropdown-link>
                        @endif

                        <!-- Authentication -->
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf

                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault();
                                                this.closest('form').submit();">
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>
        </nav>

        <!-- Mobile Menu -->
        <div class="block lg:hidden">
            <div class="flex items-center justify-between">
                <!-- Logo empresa mobile -->
                <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                    @if($companyLogoUrl ?? false)
                        <img src="{{ $companyLogoUrl }}" alt="{{ $companyName ?? '' }}" class="h-8 w-auto max-w-[120px] object-contain">
                    @else
                        <x-application-logo class="w-8 h-8 fill-current text-foreground" />
                    @endif
                </a>

                <button @click="mobileMenuOpen = true" class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 border border-input bg-background hover:bg-accent hover:text-accent-foreground h-10 w-10">
                    <x-heroicon-o-bars-3 class="h-4 w-4" />
                </button>
            </div>

            <!-- Mobile Sheet/Drawer -->
            <div x-show="mobileMenuOpen"
                x-transition:enter="duration-300 ease-out"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="duration-200 ease-in"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="fixed inset-0 z-50 bg-background/80 backdrop-blur-sm"
                style="display: none;"
                @click="mobileMenuOpen = false">
            </div>

            <div x-show="mobileMenuOpen"
                x-transition:enter="duration-500 ease-in-out"
                x-transition:enter-start="translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="duration-500 ease-in-out"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="translate-x-full"
                class="fixed inset-y-0 right-0 z-50 h-full w-3/4 gap-4 border-l bg-background p-6 shadow-lg sm:max-w-sm"
                style="display: none;"
                @click.stop>

                <div class="flex flex-col gap-6">
                    <div class="flex items-center justify-between">
                        <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                            @if($companyLogoUrl ?? false)
                                <img src="{{ $companyLogoUrl }}" alt="{{ $companyName ?? '' }}" class="h-8 w-auto max-w-[120px] object-contain">
                            @else
                                <x-application-logo class="w-8 h-8 fill-current text-foreground" />
                                <span class="text-lg font-semibold">{{ $companyName ?? config('app.name') }}</span>
                            @endif
                        </a>
                        <button @click="mobileMenuOpen = false" class="rounded-sm opacity-70 ring-offset-background transition-opacity hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2">
                            <span class="sr-only">Cerrar</span>
                            <x-heroicon-o-x-mark class="h-4 w-4" />
                        </button>
                    </div>

                    <div class="flex w-full flex-col gap-4">
                        <a href="{{ route('dashboard') }}" class="text-md font-semibold hover:underline {{ request()->routeIs('dashboard') ? 'text-primary' : '' }}">Panel</a>

                        <!-- Mobile Sales Accordion -->
                        <div x-data="{ expanded: {{ request()->routeIs(['sales.*', 'customers.*']) ? 'true' : 'false' }} }" class="border-b-0">
                            <button @click="expanded = !expanded" class="flex flex-1 items-center justify-between py-0 font-semibold transition-all hover:underline [&[data-state=open]>svg]:rotate-180 w-full text-left text-md {{ request()->routeIs(['sales.*', 'customers.*']) ? 'text-primary' : '' }}">
                                Ventas
                                <x-heroicon-o-chevron-down :class="{'rotate-180': expanded}" class="h-4 w-4 shrink-0 transition-transform duration-200" />
                            </button>
                            <div x-show="expanded" x-collapse>
                                <div class="mt-2 flex flex-col gap-2 pl-4 border-l border-border ml-2">
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs(['sales.index', 'sales.show']) ? 'text-primary' : '' }}" href="{{ route('sales.index') }}">Ventas</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('sales.create') ? 'text-primary' : '' }}" href="{{ route('sales.create') }}">Vender</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('customers.index') ? 'text-primary' : '' }}" href="{{ route('customers.index') }}">Clientes</a>
                                    @if(app(\App\Shop\Services\ShopFeatureFlag::class)->enabled() && \Illuminate\Support\Facades\Route::has('shop.admin.reservations'))
                                        @php
                                            $pendingWebReservationsMobile = \Illuminate\Support\Facades\Cache::remember(
                                                'shop.pending_web_count',
                                                30,
                                                fn () => \App\Models\Sale::where('source', 'web')
                                                    ->where('status', \App\Enums\SaleStatus::PENDING)
                                                    ->count()
                                            );
                                        @endphp
                                        <a class="text-sm font-medium hover:underline py-1 flex items-center justify-between {{ request()->routeIs('shop.admin.*') ? 'text-primary' : '' }}" href="{{ route('shop.admin.reservations') }}">
                                            <span>Reservas Web</span>
                                            @if($pendingWebReservationsMobile > 0)
                                                <span class="inline-flex items-center justify-center min-w-5 h-5 px-1.5 rounded-full text-xs font-bold bg-amber-500 text-white">{{ $pendingWebReservationsMobile }}</span>
                                            @endif
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <!-- Mobile Purchases Accordion -->
                        <div x-data="{ expanded: {{ request()->routeIs(['purchases.*', 'suppliers.*']) ? 'true' : 'false' }} }" class="border-b-0">
                            <button @click="expanded = !expanded" class="flex flex-1 items-center justify-between py-0 font-semibold transition-all hover:underline [&[data-state=open]>svg]:rotate-180 w-full text-left text-md {{ request()->routeIs(['purchases.*', 'suppliers.*']) ? 'text-primary' : '' }}">
                                Compras
                                <x-heroicon-o-chevron-down :class="{'rotate-180': expanded}" class="h-4 w-4 shrink-0 transition-transform duration-200" />
                            </button>
                            <div x-show="expanded" x-collapse>
                                <div class="mt-2 flex flex-col gap-2 pl-4 border-l border-border ml-2">
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('purchases.index') ? 'text-primary' : '' }}" href="{{ route('purchases.index') }}">Compras</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('suppliers.index') ? 'text-primary' : '' }}" href="{{ route('suppliers.index') }}">Proveedores</a>
                                </div>
                            </div>
                        </div>

                        <!-- Mobile Finance Accordion -->
                        @canany(['finance.view','finance.accounting','assets.manage','loans.manage','budgets.manage','production.manage'])
                        <div x-data="{ expanded: {{ request()->routeIs(['finance.*']) ? 'true' : 'false' }} }" class="border-b-0">
                            <button @click="expanded = !expanded" class="flex flex-1 items-center justify-between py-0 font-semibold transition-all hover:underline [&[data-state=open]>svg]:rotate-180 w-full text-left text-md {{ request()->routeIs(['finance.*']) ? 'text-primary' : '' }}">
                                Finanzas
                                <x-heroicon-o-chevron-down :class="{'rotate-180': expanded}" class="h-4 w-4 shrink-0 transition-transform duration-200" />
                            </button>
                            <div x-show="expanded" x-collapse>
                                <div class="mt-2 flex flex-col gap-2 pl-4 border-l border-border ml-2">
                                    @can('finance.view')
                                    <a class="text-sm font-semibold py-1 {{ request()->routeIs('finance.index') ? 'text-primary' : '' }}" href="{{ route('finance.index') }}">Resumen financiero</a>
                                    @endcan
                                    @can('finance.accounting')
                                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground mt-2">Contabilidad</p>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.chart-of-accounts.index') ? 'text-primary' : '' }}" href="{{ route('finance.chart-of-accounts.index') }}">Plan de cuentas</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.journal-entries.index') ? 'text-primary' : '' }}" href="{{ route('finance.journal-entries.index') }}">Libro diario</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.statements.index') ? 'text-primary' : '' }}" href="{{ route('finance.statements.index') }}">Estados financieros</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.trial-balance') ? 'text-primary' : '' }}" href="{{ route('finance.trial-balance') }}">Balance de Sumas y Saldos</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.worksheet') ? 'text-primary' : '' }}" href="{{ route('finance.worksheet') }}">Hoja Teórica</a>
                                    @endcan
                                    @can('assets.manage')
                                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground mt-2">Activos Fijos</p>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.asset-categories.index') ? 'text-primary' : '' }}" href="{{ route('finance.asset-categories.index') }}">Categorías de Activo</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.fixed-assets.*') ? 'text-primary' : '' }}" href="{{ route('finance.fixed-assets.index') }}">Activos Fijos</a>
                                    @endcan
                                    @can('loans.manage')
                                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground mt-2">Préstamos</p>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.loans.*') ? 'text-primary' : '' }}" href="{{ route('finance.loans.index') }}">Préstamos</a>
                                    @endcan
                                    @can('budgets.manage')
                                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground mt-2">Presupuestos</p>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.budgets.*') ? 'text-primary' : '' }}" href="{{ route('finance.budgets.index') }}">Presupuestos</a>
                                    @endcan
                                    @can('production.manage')
                                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground mt-2">Producción</p>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.boms.*') ? 'text-primary' : '' }}" href="{{ route('finance.boms.index') }}">Recetas (BOM)</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.production.*') ? 'text-primary' : '' }}" href="{{ route('finance.production.index') }}">Producción</a>
                                    @endcan
                                    @can('finance.view')
                                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground mt-2">Tesorería</p>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.transactions.index') ? 'text-primary' : '' }}" href="{{ route('finance.transactions.index') }}">Transacciones</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('finance.categories.index') ? 'text-primary' : '' }}" href="{{ route('finance.categories.index') }}">Categorias</a>
                                    @endcan
                                </div>
                            </div>
                        </div>
                        @endcanany

                        <!-- Mobile Users Accordion (Admin Only) -->
                        @if(auth()->user()->isAdmin())
                        <div x-data="{ expanded: {{ request()->routeIs(['users.*']) ? 'true' : 'false' }} }" class="border-b-0">
                            <button @click="expanded = !expanded" class="flex flex-1 items-center justify-between py-0 font-semibold transition-all hover:underline w-full text-left text-md {{ request()->routeIs(['users.*']) ? 'text-primary' : '' }}">
                                Usuarios
                                <x-heroicon-o-chevron-down :class="{'rotate-180': expanded}" class="h-4 w-4 shrink-0 transition-transform duration-200" />
                            </button>
                            <div x-show="expanded" x-collapse>
                                <div class="mt-2 flex flex-col gap-2 pl-4 border-l border-border ml-2">
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('users.index') ? 'text-primary' : '' }}" href="{{ route('users.index') }}">Usuarios</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('users.payroll.*') ? 'text-primary' : '' }}" href="{{ route('users.payroll.index') }}">Planilla de sueldos</a>
                                </div>
                            </div>
                        </div>
                        @endif

                        <!-- Mobile Products Accordion -->
                        <div x-data="{ expanded: {{ request()->routeIs(['products.*', 'categories.*', 'units.*']) ? 'true' : 'false' }} }" class="border-b-0">
                            <button @click="expanded = !expanded" class="flex flex-1 items-center justify-between py-0 font-semibold transition-all hover:underline [&[data-state=open]>svg]:rotate-180 w-full text-left text-md {{ request()->routeIs(['products.*', 'categories.*', 'units.*']) ? 'text-primary' : '' }}">
                                Productos
                                <x-heroicon-o-chevron-down :class="{'rotate-180': expanded}" class="h-4 w-4 shrink-0 transition-transform duration-200" />
                            </button>
                            <div x-show="expanded" x-collapse>
                                <div class="mt-2 flex flex-col gap-2 pl-4 border-l border-border ml-2">
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('products.index') ? 'text-primary' : '' }}" href="{{ route('products.index') }}">Productos</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('categories.index') ? 'text-primary' : '' }}" href="{{ route('categories.index') }}">Categorias</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('units.index') ? 'text-primary' : '' }}" href="{{ route('units.index') }}">Unidades</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('warehouses.index') ? 'text-primary' : '' }}" href="{{ route('warehouses.index') }}">Almacenes</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('locations.index') ? 'text-primary' : '' }}" href="{{ route('locations.index') }}">Ubicaciones</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('transfers.index') ? 'text-primary' : '' }}" href="{{ route('transfers.index') }}">Transferencias</a>
                                    <a class="text-sm font-medium hover:underline py-1 {{ request()->routeIs('products.kardex.index') ? 'text-primary' : '' }}" href="{{ route('products.kardex.index') }}">Kardex valorizado</a>
                                </div>
                            </div>
                        </div>


                    <!-- Mobile User Menu -->
                        <div class="pt-4 mt-4 border-t border-border">
                            <div class="font-medium text-base text-foreground mb-2">{{ Auth::user()->name }}</div>
                            <div class="flex flex-col gap-3">
                                {{-- Toggle oscuro/claro mobile --}}
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
                                    class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium border border-input h-9 px-4 py-2 w-full bg-background hover:bg-accent hover:text-accent-foreground transition-colors"
                                >
                                    <svg x-show="dark" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z" />
                                    </svg>
                                    <svg x-show="!dark" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3a7 7 0 109.79 9.79z" />
                                    </svg>
                                    <span x-text="dark ? 'Modo claro' : 'Modo oscuro'"></span>
                                </button>

                                <a href="{{ route('profile.index') }}" class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 border border-input h-9 px-4 py-2 w-full {{ request()->routeIs('profile.*') ? 'bg-accent text-accent-foreground' : 'bg-background hover:bg-accent hover:text-accent-foreground' }}">
                                    Perfil
                                </a>
                                @if(auth()->user()->isAdmin())
                                <a href="{{ route('settings.index') }}" class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 border border-input h-9 px-4 py-2 w-full {{ request()->routeIs('settings.index') ? 'bg-accent text-accent-foreground' : 'bg-background hover:bg-accent hover:text-accent-foreground' }}">
                                    Ajustes
                                </a>
                                @endif
                                @if(auth()->user()->isDeveloper())
                                <a href="{{ route('settings.backups') }}" class="inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-md text-sm font-medium border border-input h-9 px-4 py-2 w-full {{ request()->routeIs('settings.backups') ? 'bg-accent text-accent-foreground' : 'bg-background hover:bg-accent hover:text-accent-foreground' }}">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 15a4 4 0 004 4h10a4 4 0 10-.893-7.902A5 5 0 003 15z" />
                                    </svg>
                                    Backups
                                </a>
                                @endif
                                @if(auth()->user()->isDeveloper())
                                <a href="{{ route('roles.index') }}" class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium border border-input h-9 px-4 py-2 w-full {{ request()->routeIs('roles.*') ? 'bg-accent text-accent-foreground' : 'bg-background hover:bg-accent hover:text-accent-foreground' }}">
                                    🔧 Roles y permisos
                                </a>
                                @endif
                                <form method="POST" action="{{ route('logout') }}" class="w-full">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-primary text-primary-foreground hover:bg-primary/90 h-9 px-4 py-2 w-full">
                                        Salir
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
