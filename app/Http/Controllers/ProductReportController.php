<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductReportController extends Controller
{
    public function print(Request $request)
    {
        abort_if(! auth()->user()?->isAdmin(), 403);

        $from = Carbon::parse($request->input('from', now()->subDays(30)))->startOfDay();
        $to   = Carbon::parse($request->input('to', now()))->endOfDay();
        $days = max(1, $from->diffInDays($to));

        // ----------------------------------------------------------------
        // 1. Bajo stock
        // ----------------------------------------------------------------
        $lowStock = Product::where('is_active', true)
            ->whereColumn('quantity', '<=', 'min_stock')
            ->with('category:id,name')
            ->orderBy('quantity', 'asc')
            ->get();

        // ----------------------------------------------------------------
        // 2. Más vendidos
        // ----------------------------------------------------------------
        $topSelling = SaleItem::select(
                'product_id',
                DB::raw('SUM(quantity) as total_qty'),
                DB::raw('SUM(subtotal) as total_revenue')
            )
            ->whereHas('sale', fn ($q) => $q
                ->whereBetween('sale_date', [$from, $to])
                ->where('status', 'completed')
            )
            ->with('product:id,name,sku,quantity,selling_price')
            ->groupBy('product_id')
            ->orderByDesc('total_qty')
            ->get();

        // ----------------------------------------------------------------
        // 3. Sin movimiento
        // ----------------------------------------------------------------
        $soldIds = SaleItem::whereHas('sale', fn ($q) => $q
                ->whereBetween('sale_date', [$from, $to])
                ->where('status', 'completed')
            )
            ->distinct()
            ->pluck('product_id');

        $noSales = Product::where('is_active', true)
            ->whereNotIn('id', $soldIds)
            ->with('category:id,name')
            ->orderByDesc('quantity')
            ->get();

        // ----------------------------------------------------------------
        // 4. Recomendados para compra
        // ----------------------------------------------------------------
        $salesMap = SaleItem::select('product_id', DB::raw('SUM(quantity) as total_qty'))
            ->whereHas('sale', fn ($q) => $q
                ->whereBetween('sale_date', [$from, $to])
                ->where('status', 'completed')
            )
            ->groupBy('product_id')
            ->pluck('total_qty', 'product_id');

        $recommended = Product::where('is_active', true)
            ->where(function ($q) {
                $q->whereColumn('quantity', '<=', DB::raw('min_stock * 1.5'))
                  ->orWhereColumn('quantity', '<=', 'min_stock');
            })
            ->with('category:id,name')
            ->get()
            ->filter(fn ($p) => isset($salesMap[$p->id]))
            ->map(function ($p) use ($salesMap, $days) {
                $totalSold   = (int) ($salesMap[$p->id] ?? 0);
                $velocity    = $totalSold / $days;
                $daysOfStock = $velocity > 0 ? (int) ceil($p->quantity / $velocity) : null;
                $suggested   = max(0, (int) ceil($velocity * 30 - $p->quantity + $p->min_stock));

                return (object) [
                    'name'           => $p->name,
                    'sku'            => $p->sku,
                    'category'       => $p->category?->name ?? '—',
                    'quantity'       => $p->quantity,
                    'min_stock'      => $p->min_stock,
                    'total_sold'     => $totalSold,
                    'days_of_stock'  => $daysOfStock,
                    'suggested'      => $suggested,
                    'purchase_price' => $p->purchase_price,
                    'purchase_cost'  => $suggested * $p->purchase_price,
                ];
            })
            ->sortBy('days_of_stock')
            ->values();

        // ----------------------------------------------------------------
        // Empresa info
        // ----------------------------------------------------------------
        $storeName    = Setting::get('store_name', 'Mi empresa');
        $storeAddress = Setting::get('store_address', '');
        $storePhone   = Setting::get('store_phone', '');
        $storeNit     = Setting::get('store_nit', '');

        $logoPath = Setting::get('store_logo_path') ?: Setting::get('shop_logo_path');
        $logoUrl  = $logoPath ? \Illuminate\Support\Facades\Storage::url($logoPath) : null;

        $periodLabel = $from->format('d/m/Y') . ' — ' . $to->format('d/m/Y');
        $printedAt   = now()->translatedFormat('d F Y, H:i');
        $printedBy   = auth()->user()?->name ?? 'Administrador';

        return view('products.print', compact(
            'lowStock', 'topSelling', 'noSales', 'recommended',
            'storeName', 'storeAddress', 'storePhone', 'storeNit',
            'logoUrl', 'periodLabel', 'printedAt', 'printedBy',
            'from', 'to'
        ));
    }
}
