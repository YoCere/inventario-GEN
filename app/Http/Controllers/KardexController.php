<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\KardexService;
use Illuminate\Http\Request;

class KardexController extends Controller
{
    public function index(Request $request, KardexService $kardexService)
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());
        $productId = $request->integer('product_id');

        $products = Product::query()
            ->orderBy('name')
            ->get(['id', 'name', 'sku']);

        $report = null;
        if ($productId) {
            $report = $kardexService->build($productId, $from, $to);
        }

        return view('products-kardex.index', [
            'products' => $products,
            'productId' => $productId,
            'from' => $from,
            'to' => $to,
            'report' => $report,
        ]);
    }

    public function print(Request $request, KardexService $kardexService)
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to   = $request->input('to', now()->toDateString());
        $productId = $request->integer('product_id');

        if (!$productId) {
            return redirect()->route('products.kardex.index');
        }

        $report = $kardexService->build($productId, $from, $to);

        return view('products-kardex.print', compact('report', 'from', 'to'));
    }
}

