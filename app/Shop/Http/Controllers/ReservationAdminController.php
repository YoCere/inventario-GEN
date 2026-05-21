<?php

namespace App\Shop\Http\Controllers;

use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Services\SaleService;
use App\Shop\Services\WhatsAppLinkBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Admin: lista y gestiona reservas web (Sales con source='web').
 *
 * Reusa SaleService::completeSale / cancelSale para que el flujo POS quede
 * idéntico — desde aquí solo damos shortcuts visuales agrupados por status.
 */
class ReservationAdminController extends Controller
{
    public function index(WhatsAppLinkBuilder $waBuilder): View
    {
        $base = Sale::query()
            ->where('source', 'web')
            ->with(['items.product:id,name,sku', 'creator:id,name'])
            ->orderByDesc('created_at');

        $pending = (clone $base)->where('status', SaleStatus::PENDING)->limit(100)->get();
        $completed = (clone $base)->where('status', SaleStatus::COMPLETED)->limit(50)->get();
        $cancelled = (clone $base)->where('status', SaleStatus::CANCELLED)->limit(50)->get();

        return view('shop.admin.reservations.index', [
            'pending' => $pending,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'waBuilder' => $waBuilder,
            'counts' => [
                'pending' => $pending->count(),
                'completed' => $completed->count(),
                'cancelled' => $cancelled->count(),
            ],
        ]);
    }

    /**
     * Marca reserva PENDING como COMPLETED (cliente confirmó + pagó).
     * Reusa SaleService::completeSale — mismo flujo que botón "Cobrar" del POS.
     */
    public function confirm(Sale $sale, SaleService $saleService): RedirectResponse
    {
        abort_if($sale->source !== 'web', 404);
        abort_if(! auth()->user()->isAdmin(), 403);

        try {
            $saleService->completeSale($sale, [
                'cash_received' => $sale->total,
                'change' => 0,
            ]);
            Cache::forget('shop.pending_web_count');
            return back()->with('success', "Reserva {$sale->invoice_number} confirmada y completada.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Cancela reserva. Reusa SaleService::cancelSale → restaura stock automático.
     */
    public function cancel(Request $request, Sale $sale, SaleService $saleService): RedirectResponse
    {
        abort_if($sale->source !== 'web', 404);
        abort_if(! auth()->user()->isAdmin(), 403);

        try {
            $saleService->cancelSale($sale, $request->input('reason', 'Cancelada por admin desde panel reservas web.'));
            Cache::forget('shop.pending_web_count');
            return back()->with('success', "Reserva {$sale->invoice_number} cancelada. Stock restaurado.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
