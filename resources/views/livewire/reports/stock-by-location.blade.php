<div>
    <!-- Summary cards per warehouse -->
    @if($summary->isNotEmpty())
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            @foreach($summary as $row)
                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="text-xs text-gray-500 uppercase">{{ $row->wh_name }}</div>
                    <div class="mt-2 grid grid-cols-3 gap-2 text-center">
                        <div>
                            <div class="text-2xl font-bold text-indigo-600">{{ $row->productos }}</div>
                            <div class="text-xs text-gray-500">productos</div>
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-blue-600">{{ number_format($row->unidades) }}</div>
                            <div class="text-xs text-gray-500">unidades</div>
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-green-600">{{ format_money($row->valor_compra) }}</div>
                            <div class="text-xs text-gray-500">valor</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <!-- Filters -->
    <div class="bg-white border border-gray-200 rounded-lg p-4 mb-4">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <div>
                <label class="text-xs font-semibold text-gray-600">Almacén</label>
                <select wire:model.live="warehouse_id" class="mt-1 w-full h-10 rounded-md border border-input bg-background px-3 text-sm">
                    <option value="">Todos</option>
                    @foreach($warehouses as $wh)
                        <option value="{{ $wh->id }}">{{ $wh->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs font-semibold text-gray-600">Ubicación</label>
                <select wire:model.live="location_id" class="mt-1 w-full h-10 rounded-md border border-input bg-background px-3 text-sm" @disabled(!$warehouse_id)>
                    <option value="">Todas</option>
                    @foreach($locations as $loc)
                        <option value="{{ $loc->id }}">{{ $loc->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs font-semibold text-gray-600">Buscar producto</label>
                <input type="text" wire:model.live.debounce.400ms="search" placeholder="nombre o SKU"
                    class="mt-1 w-full h-10 rounded-md border border-input bg-background px-3 text-sm">
            </div>
            <div class="flex items-end">
                <label class="inline-flex items-center cursor-pointer">
                    <input type="checkbox" wire:model.live="onlyWithStock" class="w-5 h-5 rounded border-2 border-primary text-primary">
                    <span class="ml-2 text-sm font-medium text-gray-700">Solo con stock</span>
                </label>
            </div>
        </div>
    </div>

    <!-- Stock table -->
    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase">
                <tr>
                    <th class="px-4 py-3 text-left">Producto</th>
                    <th class="px-4 py-3 text-left">SKU</th>
                    <th class="px-4 py-3 text-left">Almacén</th>
                    <th class="px-4 py-3 text-left">Ubicación</th>
                    <th class="px-4 py-3 text-right">Stock</th>
                    <th class="px-4 py-3 text-right">Valor (compra)</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse($stocks as $stock)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium">{{ $stock->product?->name }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $stock->product?->sku }}</td>
                        <td class="px-4 py-3">{{ $stock->location?->warehouse?->name }}</td>
                        <td class="px-4 py-3">{{ $stock->location?->name }}</td>
                        <td class="px-4 py-3 text-right font-mono">
                            {{ $stock->quantity }} {{ $stock->product?->unit?->symbol }}
                        </td>
                        <td class="px-4 py-3 text-right font-mono">
                            {{ format_money($stock->quantity * $stock->product?->purchase_price) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-gray-500">Sin resultados.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>

        <div class="px-4 py-3 border-t border-gray-200">
            {{ $stocks->links() }}
        </div>
    </div>
</div>
