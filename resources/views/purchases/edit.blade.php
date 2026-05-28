<x-app-layout title="Editar compra">
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <h2 class="font-semibold text-xl text-foreground leading-tight">
                {{ __('Editar compra') }} #{{ $purchase->id }}
            </h2>
            <x-secondary-button href="{{ route('purchases.index') }}">
                &larr; {{ __('Volver al listado') }}
            </x-secondary-button>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <form action="{{ route('purchases.update', $purchase) }}" method="POST" enctype="multipart/form-data"
                    x-data="purchaseForm({
                        items: {{ Js::from(old('items', $purchase->items->map(function($item) {
                            return [
                                'product_id' => $item->product_id,
                                'quantity' => $item->quantity,
                                'unit_price' => $item->unit_price,
                                'selling_price' => $item->selling_price,
                                'subtotal' => $item->subtotal,
                                'key' => Str::random(10),
                                'product_name' => $item->product->name ?? '',
                                'product_code' => $item->product->sku ?? ''
                            ];
                        }))) }},
                        supplier_id: {{ Js::from(old('supplier_id', $purchase->supplier_id)) }},
                        status: {{ Js::from(old('status', $purchase->status->value)) }},
                        errors: {{ Js::from($errors->any() ? $errors->toArray() : []) }}
                    })"
                    @submit.prevent="submitForm">
                @csrf
                @method('PUT')

                @include('purchases.form')

            </form>
        </div>
    </div>
</x-app-layout>
