<?php

namespace App\Livewire\Transfers;

use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Services\TransferService;
use Livewire\Attributes\On;
use Livewire\Component;

class TransferForm extends Component
{
    public ?int $from_location_id = null;
    public ?int $to_location_id = null;
    public ?string $notes = null;
    public array $items = []; // [{product_id, quantity}]
    public ?int $newProductId = null;
    public int $newQuantity = 1;

    public function rules(): array
    {
        return [
            'from_location_id' => ['required', 'exists:locations,id', 'different:to_location_id'],
            'to_location_id' => ['required', 'exists:locations,id'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    public function render()
    {
        return view('livewire.transfers.transfer-form', [
            'locations' => Location::with('warehouse')->where('is_active', true)->orderBy('warehouse_id')->orderBy('name')->get(),
            'availableProducts' => $this->from_location_id
                ? ProductStock::with('product')
                    ->where('location_id', $this->from_location_id)
                    ->where('quantity', '>', 0)
                    ->get()
                    ->map(fn ($s) => [
                        'id' => $s->product_id,
                        'name' => $s->product?->name,
                        'sku' => $s->product?->sku,
                        'available' => $s->quantity,
                    ])
                : collect(),
        ]);
    }

    #[On('create-transfer')]
    public function open(): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $this->reset(['from_location_id', 'to_location_id', 'notes', 'items', 'newProductId', 'newQuantity']);
        $this->newQuantity = 1;
        $this->dispatch('open-modal', name: 'transfer-form-modal');
    }

    public function addItem(): void
    {
        if (!$this->newProductId || $this->newQuantity < 1) {
            $this->dispatch('toast', message: 'Selecciona producto y cantidad válida.', type: 'error');
            return;
        }

        if (!$this->from_location_id) {
            $this->dispatch('toast', message: 'Selecciona ubicación origen primero.', type: 'error');
            return;
        }

        // Validate available stock at source
        $stock = ProductStock::where('product_id', $this->newProductId)
            ->where('location_id', $this->from_location_id)
            ->first();

        if (!$stock || $stock->quantity < $this->newQuantity) {
            $available = $stock?->quantity ?? 0;
            $this->dispatch('toast', message: "Stock insuficiente en origen. Disponible: {$available}", type: 'error');
            return;
        }

        // Check if product already added
        foreach ($this->items as $i => $existing) {
            if ($existing['product_id'] === $this->newProductId) {
                $this->items[$i]['quantity'] += $this->newQuantity;
                $this->newProductId = null;
                $this->newQuantity = 1;
                return;
            }
        }

        $product = Product::find($this->newProductId);
        $this->items[] = [
            'product_id' => $this->newProductId,
            'product_name' => $product?->name,
            'product_sku' => $product?->sku,
            'quantity' => $this->newQuantity,
        ];

        $this->newProductId = null;
        $this->newQuantity = 1;
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function save(TransferService $service): void
    {
        abort_if(!auth()->user()->isAdmin(), 403);
        $this->validate();

        try {
            $transfer = $service->createTransfer([
                'from_location_id' => $this->from_location_id,
                'to_location_id' => $this->to_location_id,
                'notes' => $this->notes,
                'items' => array_map(fn ($i) => [
                    'product_id' => $i['product_id'],
                    'quantity' => $i['quantity'],
                ], $this->items),
            ], auth()->id());

            $this->dispatch('close-modal', name: 'transfer-form-modal');
            $this->dispatch('pg:eventRefresh-transfer-table');
            $this->dispatch('toast', message: "Transferencia {$transfer->reference} creada en borrador.", type: 'success');
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Error: ' . $e->getMessage(), type: 'error');
        }
    }
}
