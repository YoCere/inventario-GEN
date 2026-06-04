<div class="py-4">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-card border border-border rounded-lg shadow-sm">
            <div class="p-6 border-b border-border">
                <h3 class="text-lg font-semibold text-foreground">Nuevo Asiento Manual</h3>
                <p class="text-sm text-muted-foreground mt-1">Complete los datos del asiento contable. Debe y Haber deben cuadrar.</p>
            </div>

            <form wire:submit="save" class="p-6 space-y-6">

                {{-- Fila superior: Fecha + Tipo de comprobante + Tipo de asiento --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    {{-- Fecha --}}
                    <div class="space-y-2">
                        <x-input-label for="entry_date" value="Fecha" :required="true" />
                        <input
                            id="entry_date"
                            type="date"
                            wire:model="entry_date"
                            class="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                        />
                        <x-input-error :messages="$errors->get('entry_date')" />
                    </div>

                    {{-- Tipo de comprobante --}}
                    <div class="space-y-2">
                        <x-input-label for="voucher_type" value="Tipo de Comprobante" :required="true" />
                        <select
                            id="voucher_type"
                            wire:model="voucher_type"
                            class="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                        >
                            @foreach(\App\Enums\VoucherType::cases() as $case)
                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('voucher_type')" />
                    </div>

                    {{-- Tipo de asiento --}}
                    <div class="space-y-2">
                        <x-input-label for="entry_type" value="Tipo de Asiento" :required="true" />
                        <select
                            id="entry_type"
                            wire:model="entry_type"
                            class="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                        >
                            <option value="normal">Normal</option>
                            <option value="ajuste">Ajuste</option>
                        </select>
                        <x-input-error :messages="$errors->get('entry_type')" />
                    </div>
                </div>

                {{-- Glosa / Descripción --}}
                <div class="space-y-2">
                    <x-input-label for="description" value="Glosa (Descripción)" />
                    <textarea
                        id="description"
                        wire:model="description"
                        rows="2"
                        class="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                        placeholder="Descripción del asiento contable..."
                    ></textarea>
                    <x-input-error :messages="$errors->get('description')" />
                </div>

                {{-- Tabla de líneas --}}
                <div class="space-y-3">
                    <x-input-label value="Líneas del Asiento" :required="true" />

                    <div class="overflow-x-auto border border-border rounded-md">
                        <table class="min-w-full text-sm">
                            <thead class="bg-muted/40">
                                <tr>
                                    <th class="px-3 py-2 text-left font-medium text-muted-foreground w-5/12">Cuenta</th>
                                    <th class="px-3 py-2 text-left font-medium text-muted-foreground w-1/12">Debe/Haber</th>
                                    <th class="px-3 py-2 text-left font-medium text-muted-foreground w-2/12">Monto (Bs)</th>
                                    <th class="px-3 py-2 text-left font-medium text-muted-foreground w-3/12">Descripción línea</th>
                                    <th class="px-3 py-2 w-1/12"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($lines as $i => $line)
                                    <tr wire:key="line-{{ $i }}" class="border-t border-border">
                                        {{-- Cuenta --}}
                                        <td class="px-3 py-2">
                                            <select
                                                wire:model="lines.{{ $i }}.chart_of_account_id"
                                                class="block w-full rounded-md border border-input bg-background px-2 py-1.5 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                                            >
                                                <option value="">— Seleccionar cuenta —</option>
                                                @foreach($accountOptions as $opt)
                                                    <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                                @endforeach
                                            </select>
                                            <x-input-error :messages="$errors->get('lines.'.$i.'.chart_of_account_id')" class="mt-1" />
                                        </td>

                                        {{-- Debe/Haber --}}
                                        <td class="px-3 py-2">
                                            <select
                                                wire:model="lines.{{ $i }}.side"
                                                class="block w-full rounded-md border border-input bg-background px-2 py-1.5 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                                            >
                                                <option value="debit">Debe</option>
                                                <option value="credit">Haber</option>
                                            </select>
                                            <x-input-error :messages="$errors->get('lines.'.$i.'.side')" class="mt-1" />
                                        </td>

                                        {{-- Monto --}}
                                        <td class="px-3 py-2">
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0.01"
                                                wire:model="lines.{{ $i }}.amount"
                                                class="block w-full rounded-md border border-input bg-background px-2 py-1.5 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                                                placeholder="0.00"
                                            />
                                            <x-input-error :messages="$errors->get('lines.'.$i.'.amount')" class="mt-1" />
                                        </td>

                                        {{-- Descripción línea --}}
                                        <td class="px-3 py-2">
                                            <input
                                                type="text"
                                                wire:model="lines.{{ $i }}.description"
                                                class="block w-full rounded-md border border-input bg-background px-2 py-1.5 text-sm shadow-sm focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
                                                placeholder="Opcional"
                                            />
                                        </td>

                                        {{-- Quitar línea --}}
                                        <td class="px-3 py-2 text-center">
                                            @if(count($lines) > 2)
                                                <button
                                                    type="button"
                                                    wire:click="removeLine({{ $i }})"
                                                    class="text-red-500 hover:text-red-700 transition-colors"
                                                    title="Quitar línea"
                                                >
                                                    <x-heroicon-o-trash class="w-4 h-4" />
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            {{-- Totales en vivo --}}
                            <tfoot class="bg-muted/20 border-t border-border">
                                <tr>
                                    <td colspan="2" class="px-3 py-2 text-right font-medium text-sm">Totales:</td>
                                    <td class="px-3 py-2 text-sm font-semibold" colspan="3">
                                        <div class="flex items-center gap-4">
                                            <span>
                                                Debe: <span class="font-mono">{{ number_format($this->totalDebit(), 2) }}</span>
                                            </span>
                                            <span>
                                                Haber: <span class="font-mono">{{ number_format($this->totalCredit(), 2) }}</span>
                                            </span>
                                            @if($this->isBalanced())
                                                <span class="inline-flex items-center gap-1 text-green-600 font-medium">
                                                    <x-heroicon-o-check-circle class="w-4 h-4" />
                                                    Cuadrado
                                                </span>
                                            @else
                                                <span class="inline-flex items-center gap-1 text-red-500 font-medium">
                                                    <x-heroicon-o-exclamation-circle class="w-4 h-4" />
                                                    No cuadra
                                                </span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <x-input-error :messages="$errors->get('lines')" />

                    <div>
                        <button
                            type="button"
                            wire:click="addLine"
                            class="inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:text-primary/80 transition-colors"
                        >
                            <x-heroicon-o-plus class="w-4 h-4" />
                            Agregar línea
                        </button>
                    </div>
                </div>

                {{-- Acciones --}}
                <div class="flex justify-end gap-3 border-t border-border pt-4">
                    <x-secondary-button
                        type="button"
                        wire:navigate
                        href="{{ route('finance.journal-entries.index') }}"
                    >
                        Cancelar
                    </x-secondary-button>

                    <x-primary-button type="submit" wire:loading.attr="disabled">
                        <svg wire:loading wire:target="save" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <x-heroicon-o-check wire:loading.remove wire:target="save" class="w-4 h-4 mr-2" />
                        Guardar Asiento
                    </x-primary-button>
                </div>

            </form>
        </div>
    </div>
</div>
