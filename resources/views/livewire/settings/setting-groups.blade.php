<div class="space-y-6">
    @foreach($groups as $group)
        <section class="rounded-lg border border-border bg-card">
            <header class="border-b border-border px-4 py-3">
                <h3 class="text-base font-semibold text-foreground">{{ $group['title'] }}</h3>
            </header>
            <div class="p-4">
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

