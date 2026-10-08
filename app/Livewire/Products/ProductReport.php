<?php

namespace App\Livewire\Products;

use Carbon\Carbon;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Support\BusinessTime;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class ProductReport extends Component
{
    public string $period = '30'; // días: 30, 60, 90, 365, custom
    public string $dateFrom = '';
    public string $dateTo   = '';

    public function mount(): void
    {
        $this->dateTo   = BusinessTime::todayString();
        $this->dateFrom = BusinessTime::now()->subDays(30)->toDateString();
    }

    public function updatedPeriod(): void
    {
        if ($this->period !== 'custom') {
            $this->dateFrom = BusinessTime::now()->subDays((int) $this->period)->toDateString();
            $this->dateTo   = BusinessTime::todayString();
        }
    }

    // -------------------------------------------------------------------------

    private function range(): array
    {
        // Las fechas vienen de inputs del usuario (sin zona) y sale_date guarda hora
        // local: interpretarlas con Carbon::parse (UTC) corría el rango 4 horas.
        return [
            BusinessTime::startOfDay($this->dateFrom),
            BusinessTime::endOfDay($this->dateTo),
        ];
    }

    private function periodDays(): int
    {
        return max(1, Carbon::parse($this->dateFrom)->diffInDays(Carbon::parse($this->dateTo)));
    }

    // -------------------------------------------------------------------------
    // 1. Bajo stock
    // -------------------------------------------------------------------------

    #[Computed]
    public function lowStock(): Collection
    {
        return Product::where('is_active', true)
            ->whereColumn('quantity', '<=', 'min_stock')
            ->with('category:id,name')
            ->orderBy('quantity', 'asc')
            ->get();
    }

    // -------------------------------------------------------------------------
    // 2. Más vendidos en el periodo
    // -------------------------------------------------------------------------

    #[Computed]
    public function topSelling(): Collection
    {
        [$from, $to] = $this->range();

        return SaleItem::select(
                'product_id',
                DB::raw('SUM(quantity) as total_qty'),
                DB::raw('SUM(subtotal) as total_revenue')
            )
            ->whereHas('sale', fn ($q) => $q
                ->whereBetween('sale_date', [$from, $to])
                ->where('status', 'completed')
            )
            ->with('product:id,name,sku,quantity,min_stock,selling_price')
            ->groupBy('product_id')
            ->orderByDesc('total_qty')
            ->get();
    }

    // -------------------------------------------------------------------------
    // 3. Sin movimiento en el periodo
    // -------------------------------------------------------------------------

    #[Computed]
    public function noSales(): Collection
    {
        [$from, $to] = $this->range();

        $soldIds = SaleItem::whereHas('sale', fn ($q) => $q
            ->whereBetween('sale_date', [$from, $to])
            ->where('status', 'completed')
        )
        ->distinct()
        ->pluck('product_id');

        return Product::where('is_active', true)
            ->whereNotIn('id', $soldIds)
            ->with('category:id,name')
            ->orderByDesc('quantity')
            ->get();
    }

    // -------------------------------------------------------------------------
    // 4. Recomendados para compra
    // Lógica: tuvo demanda en el periodo Y stock <= 1.5 × min_stock
    // Sugiere cantidad para cubrir ~30 días según velocidad de venta.
    // -------------------------------------------------------------------------

    #[Computed]
    public function recommended(): Collection
    {
        [$from, $to] = $this->range();
        $days = $this->periodDays();

        // Ventas por producto en el periodo
        $sales = SaleItem::select('product_id', DB::raw('SUM(quantity) as total_qty'))
            ->whereHas('sale', fn ($q) => $q
                ->whereBetween('sale_date', [$from, $to])
                ->where('status', 'completed')
            )
            ->groupBy('product_id')
            ->pluck('total_qty', 'product_id');

        return Product::where('is_active', true)
            ->where(function ($q) {
                // Stock bajo o en zona de riesgo (< 1.5× mínimo)
                $q->whereColumn('quantity', '<=', DB::raw('min_stock * 1.5'))
                  ->orWhereColumn('quantity', '<=', 'min_stock');
            })
            ->with('category:id,name')
            ->get()
            ->filter(fn ($p) => isset($sales[$p->id])) // sólo si tuvo ventas
            ->map(function ($p) use ($sales, $days) {
                $totalSold    = (int) ($sales[$p->id] ?? 0);
                $velocity     = $totalSold / $days;             // unidades/día
                $daysOfStock  = $velocity > 0 ? (int) ceil($p->quantity / $velocity) : null;
                // Sugerencia: cubrir 30 días de demanda desde cero stock
                $suggested    = max(0, (int) ceil($velocity * 30 - $p->quantity + $p->min_stock));

                return (object) [
                    'id'               => $p->id,
                    'name'             => $p->name,
                    'sku'              => $p->sku,
                    'category'         => $p->category?->name ?? '—',
                    'quantity'         => $p->quantity,
                    'min_stock'        => $p->min_stock,
                    'total_sold'       => $totalSold,
                    'velocity'         => round($velocity, 2),
                    'days_of_stock'    => $daysOfStock,
                    'suggested'        => $suggested,
                    'purchase_price'   => $p->purchase_price,   // centavos
                    'purchase_cost'    => $suggested * $p->purchase_price,
                ];
            })
            ->sortBy('days_of_stock')
            ->values();
    }

    // -------------------------------------------------------------------------

    public function render()
    {
        return view('livewire.products.product-report', [
            'currencySymbol' => Setting::get('currency_symbol', 'Bs'),
            'lowStock'       => $this->lowStock,
            'topSelling'     => $this->topSelling,
            'noSales'        => $this->noSales,
            'recommended'    => $this->recommended,
        ]);
    }
}
