<x-modal name="journal-entry-detail-modal" focusable maxWidth="4xl">
    @if($entry)
        <div class="p-6">
            <div class="mb-6 space-y-1.5 text-center sm:text-left border-b border-gray-200 pb-4">
                <h3 class="text-lg font-semibold leading-none tracking-tight text-foreground">
                    {{ __('Detalle del Asiento Contable') }}
                </h3>
                <p class="text-sm text-muted-foreground">
                    {{ $entry->entry_number }} | {{ $entry->entry_date->format('d/m/Y') }}
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <div>
                    <p class="text-sm text-muted-foreground">Periodo</p>
                    <p class="font-medium">{{ $entry->accountingPeriod?->name ?? '-' }}</p>
                </div>
                <div>
                    <p class="text-sm text-muted-foreground">Estado</p>
                    <p class="font-medium">{{ $entry->status->label() }}</p>
                </div>
                <div>
                    <p class="text-sm text-muted-foreground">Creado por</p>
                    <p class="font-medium">{{ $entry->creator?->name ?? '-' }}</p>
                </div>
            </div>

            <div class="mb-6">
                <p class="text-sm text-muted-foreground">Descripción</p>
                <p class="font-medium">{{ $entry->description ?: '-' }}</p>
            </div>

            <div class="overflow-x-auto border rounded-md">
                <table class="min-w-full text-sm">
                    <thead class="bg-muted/40">
                        <tr>
                            <th class="px-4 py-2 text-left">#</th>
                            <th class="px-4 py-2 text-left">Cuenta</th>
                            <th class="px-4 py-2 text-left">Descripción</th>
                            <th class="px-4 py-2 text-right">Debe</th>
                            <th class="px-4 py-2 text-right">Haber</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $debitTotal = 0;
                            $creditTotal = 0;
                        @endphp
                        @foreach($entry->lines as $line)
                            @php
                                $debitTotal += (int) $line->debit_amount;
                                $creditTotal += (int) $line->credit_amount;
                            @endphp
                            <tr class="border-t">
                                <td class="px-4 py-2">{{ $line->line_number }}</td>
                                <td class="px-4 py-2">{{ $line->account?->code }} - {{ $line->account?->name }}</td>
                                <td class="px-4 py-2">{{ $line->description ?: '-' }}</td>
                                <td class="px-4 py-2 text-right">@money((int) $line->debit_amount)</td>
                                <td class="px-4 py-2 text-right">@money((int) $line->credit_amount)</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-muted/30 border-t font-semibold">
                        <tr>
                            <td colspan="3" class="px-4 py-2 text-right">Totales</td>
                            <td class="px-4 py-2 text-right">@money($debitTotal)</td>
                            <td class="px-4 py-2 text-right">@money($creditTotal)</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="mt-6 flex items-center justify-end gap-x-2 pt-4 border-t border-border">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', { name: 'journal-entry-detail-modal' })">
                    Cerrar
                </x-secondary-button>
            </div>
        </div>
    @else
        <div class="p-8 text-center flex flex-col items-center justify-center space-y-3">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
            <span class="text-sm text-muted-foreground">Cargando detalles...</span>
        </div>
    @endif
</x-modal>
