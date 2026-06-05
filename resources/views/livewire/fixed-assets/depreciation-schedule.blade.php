<div>
    <!-- Asset info card -->
    <div class="mb-6 p-4 bg-muted/50 rounded-lg border border-border">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div>
                <p class="text-muted-foreground">Código</p>
                <p class="font-semibold">{{ $asset->code }}</p>
            </div>
            <div>
                <p class="text-muted-foreground">Nombre</p>
                <p class="font-semibold">{{ $asset->name }}</p>
            </div>
            <div>
                <p class="text-muted-foreground">Categoría</p>
                <p class="font-semibold">{{ $asset->category?->name ?? '-' }}</p>
            </div>
            <div>
                <p class="text-muted-foreground">Estado</p>
                <p class="font-semibold">{{ $asset->status->label() }}</p>
            </div>
            <div>
                <p class="text-muted-foreground">Costo adquisición</p>
                <p class="font-semibold">{{ format_money($asset->acquisition_cost) }}</p>
            </div>
            <div>
                <p class="text-muted-foreground">Valor residual</p>
                <p class="font-semibold">{{ format_money($asset->residual_value) }}</p>
            </div>
            <div>
                <p class="text-muted-foreground">Vida útil</p>
                <p class="font-semibold">{{ $asset->useful_life_months }} meses</p>
            </div>
            <div>
                <p class="text-muted-foreground">Valor libro actual</p>
                <p class="font-semibold">{{ format_money($asset->bookValue()) }}</p>
            </div>
        </div>
    </div>

    <!-- Table -->
    @if(count($rows) > 0)
    <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="border-b border-border bg-muted/50">
                    <th class="px-4 py-3 text-left font-semibold text-foreground">Período</th>
                    <th class="px-4 py-3 text-right font-semibold text-foreground">Cuota depreciación</th>
                    <th class="px-4 py-3 text-right font-semibold text-foreground">Dep. acumulada</th>
                    <th class="px-4 py-3 text-right font-semibold text-foreground">Valor libro</th>
                    <th class="px-4 py-3 text-center font-semibold text-foreground">Asiento</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @foreach($rows as $row)
                <tr class="hover:bg-muted/30 transition-colors">
                    <td class="px-4 py-2 font-mono">{{ $row['year_month'] }}</td>
                    <td class="px-4 py-2 text-right">{{ format_money($row['amount']) }}</td>
                    <td class="px-4 py-2 text-right">{{ format_money($row['running_accumulated']) }}</td>
                    <td class="px-4 py-2 text-right">{{ format_money($row['running_book_value']) }}</td>
                    <td class="px-4 py-2 text-center">
                        @if($row['journal_entry_id'])
                        <a href="{{ route('finance.journal-entries.index') }}#entry-{{ $row['journal_entry_id'] }}"
                            class="text-primary hover:underline text-xs">
                            #{{ $row['journal_entry_id'] }}
                        </a>
                        @else
                        <span class="text-muted-foreground text-xs">—</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-border bg-muted/50 font-semibold">
                    <td class="px-4 py-3">Total</td>
                    <td class="px-4 py-3 text-right">{{ format_money(collect($rows)->sum('amount')) }}</td>
                    <td class="px-4 py-3"></td>
                    <td class="px-4 py-3"></td>
                    <td class="px-4 py-3"></td>
                </tr>
            </tfoot>
        </table>
    </div>
    @else
    <div class="text-center py-12 text-muted-foreground">
        <x-heroicon-o-document-text class="w-12 h-12 mx-auto mb-3 opacity-50" />
        <p>No hay registros de depreciación para este activo.</p>
    </div>
    @endif
</div>
