<div class="space-y-6">
    @foreach($groups as $group)
        <section class="rounded-lg border border-border bg-card">
            <header class="border-b border-border px-4 py-3 flex items-center justify-between">
                <h3 class="text-base font-semibold text-foreground">{{ $group['title'] }}</h3>

                @if($group['key'] === 'tienda')
                    {{-- Switch maestro inline (no usa el modal de SettingForm). --}}
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox"
                               wire:model.live="shopEnabled"
                               class="sr-only peer">
                        <div class="w-11 h-6 bg-muted rounded-full peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-ring peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-background after:border-border after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                        <span class="ml-3 text-sm font-medium text-foreground">
                            {{ $shopEnabled ? 'Activa' : 'Inactiva' }}
                        </span>
                    </label>
                @endif
            </header>

            @if($group['key'] === 'tienda' && $shopEnabled)
                {{-- Panel info: link público + QR + botones compartir. Solo visible cuando ON. --}}
                <div class="bg-green-50 dark:bg-green-950/30 border-b border-green-200 dark:border-green-900 px-4 py-4">
                    <p class="text-sm font-medium text-green-800 dark:text-green-300 mb-3">
                        ✓ Tienda en línea publicada. Comparte el enlace con tus clientes:
                    </p>

                    <div class="flex flex-col md:flex-row gap-4">
                        <div class="bg-background p-3 rounded border border-border shrink-0">
                            {!! $this->shopQrSvg !!}
                        </div>

                        <div class="flex-1 space-y-3">
                            <div>
                                <label class="text-xs uppercase tracking-wide text-muted-foreground">Enlace público</label>
                                <div class="flex items-center gap-2 mt-1"
                                     x-data="{
                                         copied: false,
                                         copy() {
                                             navigator.clipboard.writeText('{{ $this->shopPublicUrl }}');
                                             this.copied = true;
                                             setTimeout(() => this.copied = false, 1500);
                                         }
                                     }">
                                    <code class="flex-1 bg-background px-3 py-2 rounded border border-border text-sm break-all">{{ $this->shopPublicUrl }}</code>
                                    <x-secondary-button type="button" @click="copy()">
                                        <span x-show="!copied">Copiar</span>
                                        <span x-show="copied" x-cloak>✓ Copiado</span>
                                    </x-secondary-button>
                                    <a href="{{ $this->shopPublicUrl }}" target="_blank" rel="noopener">
                                        <x-secondary-button type="button">Abrir</x-secondary-button>
                                    </a>
                                </div>
                            </div>

                            <div>
                                <p class="text-xs uppercase tracking-wide text-muted-foreground mb-2">Compartir</p>
                                <div class="flex flex-wrap gap-2">
                                    <a href="https://wa.me/?text={{ urlencode('Visita nuestra tienda: ' . $this->shopPublicUrl) }}"
                                       target="_blank" rel="noopener">
                                        <x-secondary-button type="button">WhatsApp</x-secondary-button>
                                    </a>
                                    <a href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode($this->shopPublicUrl) }}"
                                       target="_blank" rel="noopener">
                                        <x-secondary-button type="button">Facebook</x-secondary-button>
                                    </a>
                                    <a href="data:image/svg+xml;base64,{{ base64_encode($this->shopQrSvg) }}"
                                       download="qr-tienda.svg">
                                        <x-secondary-button type="button">Descargar QR</x-secondary-button>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            @if($group['key'] === 'tienda')
                {{-- Panel personalización visual: logo + paleta de colores.
                     Visible siempre (también cuando OFF) para preconfigurar antes de activar. --}}
                <div class="border-b border-border px-4 py-5 space-y-6 {{ !$shopEnabled ? 'opacity-75' : '' }}">
                    <h4 class="text-sm font-semibold text-foreground flex items-center gap-2">
                        <x-heroicon-o-paint-brush class="h-4 w-4" />
                        Personalización visual
                    </h4>

                    {{-- Logo upload --}}
                    <div class="grid md:grid-cols-[200px_1fr] gap-4 items-start">
                        <div class="bg-muted/40 rounded-lg border border-dashed border-border aspect-square flex items-center justify-center overflow-hidden">
                            @if($this->shopLogoUrl)
                                <img src="{{ $this->shopLogoUrl }}"
                                     alt="Logo actual"
                                     class="max-h-full max-w-full object-contain">
                            @else
                                <div class="text-center text-muted-foreground text-xs px-3">
                                    <x-heroicon-o-photo class="h-10 w-10 mx-auto mb-2 opacity-40" />
                                    Sin logo
                                </div>
                            @endif
                        </div>

                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-foreground">Logo del negocio</label>
                            <p class="text-xs text-muted-foreground">
                                Se mostrará en el encabezado del catálogo. Formatos: PNG, JPG, SVG, WebP. Máximo 2 MB.
                                Recomendado: cuadrado o cuadrado con fondo transparente.
                            </p>
                            <div class="flex items-center gap-2 flex-wrap">
                                <label for="logo-upload" class="inline-flex items-center gap-2 px-3 py-2 rounded-md border border-input bg-background text-sm font-medium hover:bg-accent hover:text-accent-foreground cursor-pointer">
                                    <x-heroicon-o-arrow-up-tray class="h-4 w-4" />
                                    {{ $this->shopLogoUrl ? 'Cambiar logo' : 'Subir logo' }}
                                </label>
                                <input id="logo-upload"
                                       type="file"
                                       wire:model="logoUpload"
                                       class="hidden"
                                       accept="image/png,image/jpeg,image/svg+xml,image/webp">
                                @if($this->shopLogoUrl)
                                    <x-secondary-button type="button" wire:click="removeLogo" wire:confirm="¿Quitar el logo actual?">
                                        Quitar logo
                                    </x-secondary-button>
                                @endif
                            </div>

                            <div wire:loading wire:target="logoUpload" class="text-xs text-blue-600 dark:text-blue-400">
                                Subiendo logo…
                            </div>
                            @error('logoUpload')
                                <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    {{-- Paletas preset --}}
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-1">Paletas predefinidas</label>
                        <p class="text-xs text-muted-foreground mb-2">Elige una paleta de un click para aplicar los 4 colores juntos.</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach(\App\Livewire\Settings\SettingGroups::COLOR_PALETTES as $key => $palette)
                                <button type="button"
                                        wire:click="applyPalette('{{ $key }}')"
                                        class="group flex flex-col items-stretch gap-1 p-2 rounded-lg border border-border hover:border-foreground hover:shadow-sm transition-all w-32">
                                    <div class="flex h-6 rounded overflow-hidden">
                                        <div class="flex-1" style="background-color: {{ $palette['primary'] }}"></div>
                                        <div class="w-1/4" style="background-color: {{ $palette['secondary'] }}"></div>
                                        <div class="w-1/4" style="background-color: {{ $palette['accent'] }}"></div>
                                    </div>
                                    <span class="text-xs font-medium text-foreground text-center">{{ $palette['name'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    {{-- Color pickers --}}
                    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        @foreach([
                            ['property' => 'shopPrimaryColor', 'value' => $shopPrimaryColor, 'label' => 'Color principal', 'hint' => 'Botones, encabezado y enlaces.'],
                            ['property' => 'shopSecondaryColor', 'value' => $shopSecondaryColor, 'label' => 'Color secundario', 'hint' => 'Bordes y elementos auxiliares.'],
                            ['property' => 'shopAccentColor', 'value' => $shopAccentColor, 'label' => 'Color de acento', 'hint' => 'Ofertas, badges, destacados.'],
                            ['property' => 'shopTextOnPrimary', 'value' => $shopTextOnPrimary, 'label' => 'Texto sobre principal', 'hint' => 'Color del texto encima del color principal. Usualmente blanco.'],
                        ] as $field)
                            <div>
                                <label class="block text-sm font-medium text-foreground mb-1">{{ $field['label'] }}</label>
                                <div class="flex items-center gap-2">
                                    <input type="color"
                                           wire:model.live.debounce.300ms="{{ $field['property'] }}"
                                           class="h-10 w-14 rounded border border-input cursor-pointer">
                                    <input type="text"
                                           wire:model.live.debounce.500ms="{{ $field['property'] }}"
                                           maxlength="7"
                                           placeholder="#000000"
                                           class="flex-1 h-10 px-2 rounded-md border border-input bg-background text-sm font-mono uppercase">
                                </div>
                                <p class="text-xs text-muted-foreground mt-1">{{ $field['hint'] }}</p>
                            </div>
                        @endforeach
                    </div>

                    {{-- Live preview --}}
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-2">Vista previa</label>
                        <div class="rounded-lg overflow-hidden border border-border">
                            {{-- Header preview --}}
                            <div class="px-4 py-3 flex items-center justify-between"
                                 style="background-color: {{ $shopPrimaryColor }}; color: {{ $shopTextOnPrimary }}">
                                <div class="flex items-center gap-2">
                                    @if($this->shopLogoUrl)
                                        <img src="{{ $this->shopLogoUrl }}" alt="" class="h-8 w-8 object-contain bg-white/20 rounded p-0.5">
                                    @endif
                                    <span class="font-semibold">{{ \App\Models\Setting::get('shop_business_name', 'Mi Tienda') ?: 'Mi Tienda' }}</span>
                                </div>
                                <div class="flex items-center gap-3 text-sm">
                                    <span>🛒 Carrito</span>
                                </div>
                            </div>
                            {{-- Body preview --}}
                            <div class="p-4 bg-white dark:bg-zinc-900 flex items-center gap-4">
                                <div class="w-16 h-16 rounded bg-muted flex items-center justify-center text-2xl">📦</div>
                                <div class="flex-1">
                                    <p class="font-medium text-foreground">Ejemplo Producto</p>
                                    <p class="text-sm" style="color: {{ $shopSecondaryColor }}">SKU-001</p>
                                </div>
                                <div class="text-right">
                                    <p class="font-bold text-lg" style="color: {{ $shopPrimaryColor }}">Bs. 65</p>
                                    <span class="inline-block text-xs px-2 py-0.5 rounded-full font-medium"
                                          style="background-color: {{ $shopAccentColor }}; color: {{ $shopTextOnPrimary }}">
                                        ¡Oferta!
                                    </span>
                                </div>
                            </div>
                            <div class="px-4 py-3 bg-white dark:bg-zinc-900 border-t border-border">
                                <button class="px-4 py-2 rounded-md text-sm font-medium"
                                        style="background-color: {{ $shopPrimaryColor }}; color: {{ $shopTextOnPrimary }}">
                                    Añadir al carrito
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <div class="p-4 {{ $group['key'] === 'tienda' && !$shopEnabled ? 'opacity-50' : '' }}">
                @if($group['key'] === 'tienda' && !$shopEnabled)
                    <p class="text-xs text-muted-foreground mb-3 italic">
                        Configura los campos abajo antes de activar. La tienda no será accesible hasta que actives el switch arriba.
                    </p>
                @endif

                @if(empty($group['items']))
                    <p class="text-sm text-muted-foreground">Sin ajustes en esta sección.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-muted-foreground border-b border-border">
                                    <th class="py-2 pr-2">Ajuste</th>
                                    <th class="py-2 pr-2">Valor</th>
                                    <th class="py-2 text-right">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($group['items'] as $item)
                                    @php
                                        // Skip filas editadas inline en panel de Tienda.
                                        $inlineShopKeys = [
                                            'shop_enabled',
                                            'shop_logo_path',
                                            'shop_primary_color',
                                            'shop_secondary_color',
                                            'shop_accent_color',
                                            'shop_text_on_primary',
                                        ];
                                    @endphp
                                    @if(in_array($item['key'], $inlineShopKeys, true))
                                        @continue
                                    @endif
                                    <tr class="border-b border-border/60">
                                        <td class="py-2 pr-2">{{ $item['label'] }}</td>
                                        <td class="py-2 pr-2 text-foreground">{{ $item['value'] }}</td>
                                        <td class="py-2 text-right">
                                            <x-secondary-button type="button" wire:click="editSetting('{{ $item['key'] }}')">
                                                Editar
                                            </x-secondary-button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>
    @endforeach
</div>
