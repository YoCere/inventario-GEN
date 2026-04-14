<x-app-layout title="Detalles de Venta">
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                {{ __('Detalles de Venta') }} #{{ $sale->invoice_number ?: $sale->id }}
            </h2>
            <div class="flex items-center gap-2">
                <x-secondary-button href="{{ route('sales.index') }}">
                    &larr; {{ __('Volver al Listado') }}
                </x-secondary-button>
                <x-primary-button href="{{ route('sales.print', $sale) }}" target="_blank">
                    <x-heroicon-o-printer class="w-4 h-4 mr-2" />
                    {{ __('Imprimir Factura') }}
                </x-primary-button>
            </div>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <!-- Tarjeta de Información Principal -->
            <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden border border-gray-200">
                <div class="p-6">
                    <!-- Información de Cabecera -->
                    <div class="flex items-start justify-between border-b border-gray-100 pb-4 mb-6">
                        <div>
                            <h3 class="text-lg font-medium text-gray-900">{{ __('Información de la Venta') }}</h3>
                            <p class="text-sm text-gray-500">{{ __('Detalles de la transacción de venta') }}</p>
                        </div>
                        <div class="px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-700 text-xs font-medium border border-slate-200">
                            ID: #{{ $sale->id }}
                        </div>
                    </div>

                    <!-- Cuadrícula de Contenido -->
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <!-- Cliente -->
                        <x-detail-item label="Cliente" :value="$sale->customer->name ?? 'Invitado'">
                            <x-heroicon-o-user class="w-4 h-4 text-gray-400" />
                        </x-detail-item>

                        <!-- Factura -->
                        <x-detail-item label="Número de Factura" :value="$sale->invoice_number ?? '-'">
                            <x-heroicon-o-document-text class="w-4 h-4 text-gray-400" />
                        </x-detail-item>

                        <!-- Fecha de Venta -->
                        <x-detail-item label="Fecha de Venta" :value="$sale->sale_date->format('d M Y')">
                            <x-heroicon-o-calendar class="w-4 h-4 text-gray-400" />
                        </x-detail-item>

                        <!-- Método de Pago -->
                        <x-detail-item label="Método de Pago" :value="$sale->payment_method->label()">
                            <x-heroicon-o-credit-card class="w-4 h-4 text-gray-400" />
                        </x-detail-item>

                        <!-- Estado -->
                        <div>
                            <label class="text-sm font-medium leading-none text-gray-500">Estado</label>
                            <div class="mt-1">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-medium border {{ $sale->status->color() }}">
                                    {{ $sale->status->label() }}
                                </span>
                            </div>
                        </div>

                        <!-- Creado Por -->
                        <x-detail-item label="Creado Por" :value="$sale->creator->name ?? 'Desconocido'">
                            <x-heroicon-o-user class="w-4 h-4 text-gray-400" />
                        </x-detail-item>
                    </div>

                    <!-- Notas -->
                    <div class="mt-6 pt-6 border-t border-gray-100">
                        <div class="space-y-1">
                            <label class="text-sm font-medium leading-none text-gray-500">
                                Notas
                            </label>
                            <div class="bg-gray-50 p-3 rounded-md border border-gray-100">
                                <p class="text-sm text-slate-700 italic leading-relaxed">{{ $sale->notes ?: 'Sin notas adicionales.' }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Sección de Tabla de Productos -->
                    <div class="mt-6 border-t overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3">Código</th>
                                    <th class="px-6 py-3">Producto</th>
                                    <th class="px-6 py-3">Unidad</th>
                                    <th class="px-6 py-3 text-center">Cant.</th>
                                    <th class="px-6 py-3 text-right">Precio</th>
                                    <th class="px-6 py-3 text-right">Descuento</th>
                                    <th class="px-6 py-3 text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach($sale->items as $item)
                                    <tr class="bg-white hover:bg-gray-50">
                                        <td class="px-6 py-4 text-sm text-gray-500">
                                            {{ $item->product->product_code ?? $item->product->sku ?? '-' }}
                                        </td>
                                        <td class="px-6 py-4 font-medium text-gray-900">
                                            {{ $item->product->name }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500">
                                            {{ $item->product->unit->symbol ?? $item->product->unit->name ?? '-' }}
                                        </td>
                                        <td class="px-6 py-4 text-center">
                                            {{ number_format($item->quantity) }}
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            @money($item->unit_price)
                                        </td>
                                        <td class="px-6 py-4 text-right text-red-500">
                                            {!! $item->discount > 0 ? "- <span>" . format_money($item->discount) . "</span>" : '-' !!}
                                        </td>
                                        <td class="px-6 py-4 text-right font-medium">
                                            @money($item->subtotal)
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-gray-50 font-bold">
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-right">Subtotal</td>
                                    <td class="px-6 py-4 text-right text-gray-700">
                                        @money($sale->subtotal)
                                    </td>
                                </tr>
                                @if($sale->total_discount > 0)
                                    <tr>
                                        <td colspan="6" class="px-6 py-4 text-right text-red-600">Total Descuentos (Productos)</td>
                                        <td class="px-6 py-4 text-right text-red-600">
                                            - @money($sale->total_discount - $sale->global_discount)
                                        </td>
                                    </tr>
                                @endif
                                @if($sale->global_discount > 0)
                                    <tr>
                                        <td colspan="6" class="px-6 py-4 text-right text-red-600">Descuento Global (Transacción)</td>
                                        <td class="px-6 py-4 text-right text-red-600">
                                            - @money($sale->global_discount)
                                        </td>
                                    </tr>
                                @endif
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-right">Total</td>
                                    <td class="px-6 py-4 text-right text-indigo-600 text-lg">
                                        @money($sale->total)
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-right text-gray-600">Efectivo Recibido</td>
                                    <td class="px-6 py-4 text-right text-gray-800">
                                        @money($sale->cash_received)
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-right text-gray-600">Cambio</td>
                                    <td class="px-6 py-4 text-right text-green-600">
                                        @money($sale->change)
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Botones de Acción - Flujo de Trabajo -->
            <div x-data="{
                actionUrl: '',
                actionMethod: '',
                modalTitle: '',
                modalMessage: '',
                confirmButtonText: '',
                confirmButtonClass: '',

                confirmAction(url, method, title, message, btnText, btnClass) {
                    this.actionUrl = url;
                    this.actionMethod = method;
                    this.modalTitle = title;
                    this.modalMessage = message;
                    this.confirmButtonText = btnText;
                    this.confirmButtonClass = btnClass;
                    $dispatch('open-modal', { name: 'confirmation-modal' });
                }
            }" class="flex flex-col sm:flex-row justify-end gap-4">

                @if($sale->status === \App\Enums\SaleStatus::PENDING)
                    {{-- Acción Completar / Pagar --}}
                    <x-primary-button
                        class="!bg-green-600 hover:!bg-green-700 focus:!ring-green-500"
                        @click="confirmAction('{{ route('sales.complete', $sale) }}', 'PATCH', 'Completar Venta', '¿Marcar esta venta como Completada? Esto confirma que se ha recibido el pago.', 'Completar Venta', '!bg-green-600 hover:!bg-green-700 focus:!ring-green-500')"
                    >
                        {{ __('Completar Venta') }}
                    </x-primary-button>

                    {{-- Acción Cancelar Reservada (Modal) --}}
                    <div x-data="{ cancelOpen: false }">
                        <x-danger-button @click="cancelOpen = true">
                            {{ __('Cancelar Venta') }}
                        </x-danger-button>

                        <!-- Modal de Cancelación -->
                        <div x-show="cancelOpen"
                             style="display: none;"
                             x-transition.opacity
                             class="fixed inset-0 z-50 overflow-y-auto bg-gray-900 bg-opacity-75 flex items-center justify-center p-4">

                            <div @click.outside="cancelOpen = false"
                                 x-transition.scale
                                 class="relative bg-white rounded-lg max-w-md w-full p-6 shadow-xl text-left">

                                <h3 class="text-lg font-medium text-gray-900 mb-2">
                                    {{ __('Cancelar venta reservada') }}
                                </h3>
                                <p class="text-sm text-gray-500 mb-4">
                                    {{ __('¿Está seguro de que desea cancelar esta venta reservada? Por favor, proporcione un motivo.') }}
                                </p>

                                <form action="{{ route('sales.destroy', $sale) }}" method="POST">
                                    @csrf
                                    @method('DELETE')

                                    <div class="mb-4">
                                        <x-input-label for="reason" :value="__('Motivo')" />
                                        <textarea
                                            name="reason"
                                            id="reason"
                                            rows="3"
                                            class="block w-full mt-1 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                            placeholder="El cliente cambió de opinión..."
                                            required
                                        ></textarea>
                                    </div>

                                    <div class="mt-6 flex justify-end gap-3">
                                        <x-secondary-button type="button" @click="cancelOpen = false">
                                            {{ __('Volver') }}
                                        </x-secondary-button>
                                        <x-danger-button type="submit">
                                            {{ __('Cancelar Venta') }}
                                        </x-danger-button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                @endif

                @if($sale->status === \App\Enums\SaleStatus::COMPLETED)
                    {{-- Acción Cancelar --}}
                    <x-secondary-button
                        class="text-red-600 hover:bg-red-50 border-red-200"
                        @click="confirmAction('{{ route('sales.destroy', $sale) }}', 'DELETE', 'Cancelar Venta', '¿Está seguro de que desea cancelar (ANULAR) esta venta? Se devolverá el stock.', 'Sí, Cancelar Venta', '!bg-red-600 hover:!bg-red-700 focus:!ring-red-500')"
                    >
                        {{ __('Cancelar Venta') }}
                    </x-secondary-button>
                @endif

                @if($sale->status === \App\Enums\SaleStatus::CANCELLED)
                    {{-- Acción Restaurar --}}
                    <x-secondary-button
                        class="bg-gray-800 text-white hover:bg-gray-700 focus:ring-gray-500"
                        @click="confirmAction('{{ route('sales.restore', $sale) }}', 'PATCH', 'Restaurar venta', '¿Restaurar esta venta a estado reservado? Luego podrás completarla nuevamente.', 'Restaurar a reservado', '!bg-gray-800 hover:!bg-gray-700 text-white')"
                    >
                        {{ __('Restaurar a reservado') }}
                    </x-secondary-button>
                @endif

                <!-- Modal de Confirmación Compartido -->
                <x-modal name="confirmation-modal">
                    <div class="p-6" x-data="{ submitting: false }">
                        <h2 class="text-lg font-medium text-gray-900" x-text="modalTitle"></h2>

                        <p class="mt-1 text-sm text-gray-600" x-text="modalMessage"></p>

                        <div class="mt-6 flex justify-end">
                            <x-secondary-button x-on:click="$dispatch('close-modal', { name: 'confirmation-modal' })" x-bind:disabled="submitting">
                                {{ __('Volver') }}
                            </x-secondary-button>

                            <form :action="actionUrl" method="POST" class="ml-3" @submit="submitting = true">
                                @csrf
                                <input type="hidden" name="_method" :value="actionMethod">

                                <button
                                    type="submit"
                                    class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 h-10 px-4 py-2 text-white shadow-sm bg-primary"
                                    x-bind:class="confirmButtonClass + (submitting ? ' opacity-75 cursor-not-allowed' : '')"
                                    x-bind:disabled="submitting"
                                >
                                    <svg x-show="submitting" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    <span x-text="confirmButtonText"></span>
                                </button>
                            </form>
                        </div>
                    </div>
                </x-modal>

            </div>
        </div>
    </div>
</x-app-layout>
