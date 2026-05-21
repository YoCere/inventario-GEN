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
                {{-- Panel de info: link público + QR + botones compartir. Solo visible cuando ON. --}}
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
                                    @if($item['key'] === 'shop_enabled')
                                        {{-- El master switch ya está en el header de esta sección. Skip la fila. --}}
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
