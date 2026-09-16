<div x-data="{ dirty: false }"
     x-on:settings-saved.window="dirty = false"
     x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = ''; }"
     class="space-y-6">

    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-foreground">Ajustes</h1>
        <p class="mt-1 text-sm text-muted-foreground">Cada sección se guarda por separado.</p>
    </div>

    <div class="grid grid-cols-[minmax(0,1fr)] items-start gap-6 lg:grid-cols-[240px_minmax(0,1fr)] lg:gap-8">

        {{-- Menú de secciones --}}
        <nav aria-label="Secciones de ajustes"
             class="-mx-4 flex min-w-0 gap-1 overflow-x-auto px-4 pb-1 lg:mx-0 lg:flex-col lg:overflow-visible lg:px-0 lg:pb-0">
            @php $technicalShown = false; @endphp
            @foreach($this->sections as $key => $item)
                @if($key === 'sistema' && ! $technicalShown)
                    @php $technicalShown = true; @endphp
                    <p class="hidden px-3 pb-1 pt-4 text-xs font-medium text-muted-foreground lg:block">Técnico</p>
                @endif
                <button type="button"
                        x-on:click="if (!dirty || confirm('Tienes cambios sin guardar. ¿Salir sin guardar?')) { dirty = false; $wire.goTo('{{ $key }}') }"
                        @if($section === $key) aria-current="page" @endif
                        @class([
                            'flex h-10 shrink-0 items-center gap-2.5 whitespace-nowrap rounded-lg px-3 text-left text-sm transition-colors lg:h-11 lg:w-full',
                            'bg-card font-semibold text-foreground shadow-sm ring-1 ring-border' => $section === $key,
                            'text-muted-foreground hover:bg-muted hover:text-foreground' => $section !== $key,
                        ])>
                    <x-dynamic-component :component="'heroicon-o-' . $item['icon']" class="h-5 w-5 shrink-0" />
                    <span class="flex-1">{{ $item['title'] }}</span>
                    @if($key === 'tienda')
                        @php $shopOn = \App\Models\Setting::get('shop_enabled') === '1'; @endphp
                        <span class="hidden text-xs font-medium lg:inline {{ $shopOn ? 'text-emerald-700 dark:text-emerald-400' : 'text-muted-foreground' }}">
                            {{ $shopOn ? 'Activa' : 'Apagada' }}
                        </span>
                    @endif
                </button>
            @endforeach
        </nav>

        {{-- Formulario de la sección --}}
        <form wire:submit="save" x-on:input="dirty = true" x-on:change="dirty = true"
              class="min-w-0 rounded-xl border border-border bg-card" wire:key="section-{{ $section }}">

            <div class="border-b border-border px-5 py-5 sm:px-7">
                <h2 class="text-lg font-semibold text-foreground">{{ $current['title'] }}</h2>
                <p class="mt-1 text-sm text-muted-foreground">{{ $current['description'] }}</p>
            </div>

            <div class="space-y-8 px-5 py-6 sm:px-7">

                {{-- Mi negocio: logo --}}
                @if($section === 'negocio')
                    @include('livewire.settings.partials.logo', [
                        'url' => $this->companyLogoUrl,
                        'model' => 'companyLogoUpload',
                        'remove' => 'removeCompanyLogo',
                        'label' => 'Logo',
                        'help' => 'Se ve en la barra superior y en los reportes. PNG, JPG o SVG, hasta 2 MB.',
                    ])
                @endif

                {{-- Tienda: estado y enlace para compartir --}}
                @if($section === 'tienda' && \App\Models\Setting::get('shop_enabled') === '1')
                    <div class="flex flex-col gap-5 rounded-lg bg-emerald-50 p-4 dark:bg-emerald-950/30 sm:flex-row">
                        <div class="shrink-0 self-start rounded-md bg-white p-2">{!! $this->shopQrSvg !!}</div>
                        <div class="min-w-0 flex-1 space-y-3" x-data="{ copied: false }">
                            <div>
                                <p class="text-sm font-semibold text-emerald-900 dark:text-emerald-200">Tu tienda está publicada</p>
                                <p class="mt-0.5 text-sm text-emerald-800/80 dark:text-emerald-300/80">Comparte el enlace o el código QR con tus clientes.</p>
                            </div>
                            <p class="break-all rounded-md border border-emerald-200 bg-white px-3 py-2 font-mono text-sm text-foreground dark:border-emerald-900 dark:bg-background">{{ $this->shopPublicUrl }}</p>
                            <div class="flex flex-wrap gap-2">
                                <x-secondary-button type="button" x-on:click="navigator.clipboard.writeText(@js($this->shopPublicUrl)); copied = true; setTimeout(() => copied = false, 1500)">
                                    <span x-text="copied ? 'Copiado' : 'Copiar enlace'">Copiar enlace</span>
                                </x-secondary-button>
                                <a href="{{ $this->shopPublicUrl }}" target="_blank" rel="noopener">
                                    <x-secondary-button type="button">Abrir tienda</x-secondary-button>
                                </a>
                                <a href="https://wa.me/?text={{ urlencode('Visita nuestra tienda: ' . $this->shopPublicUrl) }}" target="_blank" rel="noopener">
                                    <x-secondary-button type="button">Compartir por WhatsApp</x-secondary-button>
                                </a>
                                <a href="data:image/svg+xml;base64,{{ base64_encode($this->shopQrSvg) }}" download="qr-tienda.svg">
                                    <x-secondary-button type="button">Descargar QR</x-secondary-button>
                                </a>
                            </div>
                        </div>
                    </div>
                @elseif($section === 'tienda' && ! auth()->user()->can('settings.edit-technical'))
                    <div class="rounded-lg border border-border bg-muted/50 px-4 py-3 text-sm text-muted-foreground">
                        Tu tienda aún no está publicada. Deja todo listo aquí y pide que la activen.
                    </div>
                @endif

                {{-- Campos principales, por grupo --}}
                @foreach($basicGroups as $groupTitle => $groupFields)
                    <div class="space-y-4">
                        @if($groupTitle !== '')
                            <h3 class="text-sm font-semibold text-foreground">{{ $groupTitle }}</h3>
                        @endif
                        <div class="grid gap-x-5 gap-y-5 sm:grid-cols-2">
                            @foreach($groupFields as $field)
                                @include('livewire.settings.partials.field', ['field' => $field, 'live' => $section === 'moneda'])
                            @endforeach
                        </div>
                    </div>
                @endforeach

                {{-- Moneda: vista previa --}}
                @if($section === 'moneda')
                    <div class="flex items-center justify-between gap-4 rounded-lg bg-muted/60 px-4 py-3">
                        <span class="text-sm text-muted-foreground">Así se verá un precio</span>
                        <span class="text-lg font-semibold text-foreground">{{ $this->currencyPreview }}</span>
                    </div>
                @endif

                {{-- Tienda: apariencia --}}
                @if($section === 'tienda')
                    <div class="space-y-5 border-t border-border pt-6">
                        <h3 class="text-sm font-semibold text-foreground">Apariencia</h3>

                        @include('livewire.settings.partials.logo', [
                            'url' => $this->shopLogoUrl,
                            'model' => 'shopLogoUpload',
                            'remove' => 'removeShopLogo',
                            'label' => 'Logo de la tienda',
                            'help' => 'Se ve en el encabezado del catálogo. Mejor cuadrado y con fondo transparente.',
                        ])

                        <div class="space-y-2">
                            <p class="text-sm font-medium text-foreground">Colores</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach(\App\Settings\SettingsCatalog::SHOP_PALETTES as $paletteKey => $palette)
                                    @php
                                        $selected = strtoupper($values['shop_primary_color'] ?? '') === $palette['primary']
                                            && strtoupper($values['shop_accent_color'] ?? '') === $palette['accent'];
                                    @endphp
                                    <button type="button" wire:click="applyPalette('{{ $paletteKey }}')" x-on:click="dirty = true"
                                            aria-pressed="{{ $selected ? 'true' : 'false' }}"
                                            @class([
                                                'flex w-28 flex-col gap-1.5 rounded-lg border p-2 text-xs font-medium text-foreground transition-colors',
                                                'border-foreground ring-1 ring-foreground' => $selected,
                                                'border-border hover:border-muted-foreground' => ! $selected,
                                            ])>
                                        <span class="flex h-6 overflow-hidden rounded">
                                            <span class="flex-1" style="background-color: {{ $palette['primary'] }}"></span>
                                            <span class="w-1/4" style="background-color: {{ $palette['secondary'] }}"></span>
                                            <span class="w-1/4" style="background-color: {{ $palette['accent'] }}"></span>
                                        </span>
                                        {{ $palette['name'] }}
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        {{-- Vista previa del catálogo --}}
                        @php
                            $primary = $values['shop_primary_color'] ?? '#2563EB';
                            $onPrimary = $values['shop_text_on_primary'] ?? '#FFFFFF';
                            $accent = $values['shop_accent_color'] ?? '#F59E0B';
                            $shopName = ($values['shop_business_name'] ?? '') !== '' ? $values['shop_business_name'] : \App\Shop\ShopSettings::businessName();
                        @endphp
                        <div class="overflow-hidden rounded-lg border border-border" aria-label="Vista previa de la tienda">
                            <div class="flex items-center gap-2 px-4 py-3" style="background-color: {{ $primary }}; color: {{ $onPrimary }}">
                                @if($this->shopLogoUrl)
                                    <img src="{{ $this->shopLogoUrl }}" alt="" class="h-7 w-7 rounded bg-white/20 object-contain p-0.5">
                                @endif
                                <span class="font-semibold">{{ $shopName }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-4 bg-white px-4 py-3 dark:bg-zinc-900">
                                <div>
                                    <p class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Producto de ejemplo</p>
                                    <p class="text-sm font-semibold" style="color: {{ $primary }}">{{ \App\Shop\ShopSettings::currencySymbol() }} 65,00</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium" style="background-color: {{ $accent }}; color: {{ $onPrimary }}">Oferta</span>
                                    <span class="rounded-md px-3 py-1.5 text-sm font-medium" style="background-color: {{ $primary }}; color: {{ $onPrimary }}">Pedir</span>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Bloque avanzado plegable --}}
                @if($advancedFields->isNotEmpty())
                    @php
                        $advancedKeys = $advancedFields->pluck('key')->map(fn ($k) => 'values.' . $k)->all();
                        $advancedHasErrors = collect($advancedKeys)->contains(fn ($k) => $errors->has($k)) || $errors->has('password');
                    @endphp
                    <div x-data="{ open: @js($advancedHasErrors) }" class="rounded-lg border border-border">
                        <button type="button" x-on:click="open = !open" :aria-expanded="open ? 'true' : 'false'"
                                class="flex w-full items-center justify-between gap-4 px-4 py-3.5 text-left">
                            <span>
                                <span class="block text-sm font-semibold text-foreground">{{ $current['advanced_title'] ?? 'Opciones avanzadas' }}</span>
                                @isset($current['advanced_help'])
                                    <span class="mt-0.5 block text-sm text-muted-foreground">{{ $current['advanced_help'] }}</span>
                                @endisset
                            </span>
                            <x-heroicon-o-chevron-down class="h-4 w-4 shrink-0 text-muted-foreground transition-transform" x-bind:class="open && 'rotate-180'" />
                        </button>
                        <div x-show="open" x-collapse x-cloak>
                            <div class="grid gap-x-5 gap-y-5 border-t border-border px-4 py-5 sm:grid-cols-2">
                                @foreach($advancedFields as $field)
                                    @include('livewire.settings.partials.field', ['field' => $field])
                                @endforeach

                                @if($hasConfirmFields)
                                    <div class="space-y-1.5 rounded-lg bg-amber-50 p-3 dark:bg-amber-950/30 sm:col-span-2">
                                        <x-input-label for="settings-password" value="Tu contraseña" />
                                        <x-text-input id="settings-password" type="password" wire:model="password" autocomplete="current-password" class="sm:max-w-xs" />
                                        <p class="text-sm text-amber-800 dark:text-amber-300">Solo si cambias el saldo inicial de caja.</p>
                                        @error('password')
                                            <p class="text-sm text-destructive">{{ $message }}</p>
                                        @enderror
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Enlaces a pantallas relacionadas --}}
                @if($section === 'tienda' && auth()->user()->can('shop.landing.manage'))
                    <a href="{{ route('settings.shop-landing') }}" class="flex items-center justify-between rounded-lg border border-border px-4 py-3.5 text-sm hover:bg-muted">
                        <span>
                            <span class="block font-medium text-foreground">Página de presentación</span>
                            <span class="block text-muted-foreground">Lo que ven los visitantes al entrar: horarios, quiénes somos y botón al catálogo.</span>
                        </span>
                        <x-heroicon-o-chevron-right class="h-4 w-4 shrink-0 text-muted-foreground" />
                    </a>
                @endif
                @if($section === 'contabilidad')
                    <a href="{{ route('finance.accounting-periods.index') }}" class="flex items-center justify-between rounded-lg border border-border px-4 py-3.5 text-sm hover:bg-muted">
                        <span>
                            <span class="block font-medium text-foreground">Períodos contables</span>
                            <span class="block text-muted-foreground">Tipo de período y creación automática del siguiente.</span>
                        </span>
                        <x-heroicon-o-chevron-right class="h-4 w-4 shrink-0 text-muted-foreground" />
                    </a>
                @endif
                @if($section === 'sistema' && auth()->user()->isDeveloper())
                    <a href="{{ route('settings.backups') }}" class="flex items-center justify-between rounded-lg border border-border px-4 py-3.5 text-sm hover:bg-muted">
                        <span>
                            <span class="block font-medium text-foreground">Respaldos</span>
                            <span class="block text-muted-foreground">Crear, descargar y limpiar copias de seguridad.</span>
                        </span>
                        <x-heroicon-o-chevron-right class="h-4 w-4 shrink-0 text-muted-foreground" />
                    </a>
                @endif
            </div>

            <div class="sticky bottom-0 flex items-center justify-end gap-3 rounded-b-xl border-t border-border bg-card/95 px-5 py-4 backdrop-blur sm:px-7">
                <span x-show="dirty" x-cloak class="text-sm text-muted-foreground">Cambios sin guardar</span>
                <x-primary-button wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Guardar cambios</span>
                    <span wire:loading wire:target="save">Guardando…</span>
                </x-primary-button>
            </div>
        </form>
    </div>
</div>
