<?php

namespace App\Livewire\Reports;

use App\Models\Location;
use App\Models\ProductStock;
use App\Models\Warehouse;
use Livewire\Component;
use Livewire\WithPagination;

class StockByLocation extends Component
{
    use WithPagination;

    public ?int $warehouse_id = null;
    public ?int $location_id = null;
    public string $search = '';
    public bool $onlyWithStock = true;

    protected $queryString = ['warehouse_id', 'location_id', 'search', 'onlyWithStock'];

    public function updating($name): void
    {
        $this->resetPage();
        if ($name === 'warehouse_id') {
            $this->location_id = null;
        }
    }

    public function render()
    {
        $query = ProductStock::query()
            ->with(['product.unit', 'product.category', 'location.warehouse'])
            ->when($this->onlyWithStock, fn ($q) => $q->where('quantity', '>', 0))
            ->when($this->location_id, fn ($q) => $q->where('location_id', $this->location_id))
            ->when($this->warehouse_id && !$this->location_id, function ($q) {
                $q->whereHas('location', fn ($lq) => $lq->where('warehouse_id', $this->warehouse_id));
            })
            ->when($this->search, function ($q) {
                $term = '%' . $this->search . '%';
                $q->whereHas('product', fn ($pq) => $pq->where('name', 'like', $term)->orWhere('sku', 'like', $term));
            });

        $stocks = $query->orderBy('location_id')->paginate(25);

        // Summary by warehouse
        $summary = ProductStock::query()
            ->join('locations', 'locations.id', '=', 'product_stocks.location_id')
            ->join('warehouses', 'warehouses.id', '=', 'locations.warehouse_id')
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->selectRaw('warehouses.id as wh_id, warehouses.name as wh_name, COUNT(DISTINCT product_stocks.product_id) as productos, SUM(product_stocks.quantity) as unidades, SUM(product_stocks.quantity * products.purchase_price) as valor_compra')
            ->where('product_stocks.quantity', '>', 0)
            ->groupBy('warehouses.id', 'warehouses.name')
            ->get();

        return view('livewire.reports.stock-by-location', [
            'stocks' => $stocks,
            'warehouses' => Warehouse::orderBy('name')->get(),
            'locations' => $this->warehouse_id
                ? Location::where('warehouse_id', $this->warehouse_id)->orderBy('name')->get()
                : collect(),
            'summary' => $summary,
        ]);
    }
}
