<?php

namespace App\Shop\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Shop\Events\WebReservationCreated;
use App\Shop\Services\ReservationService;
use App\Shop\Services\WhatsAppLinkBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ReservationController extends Controller
{
    public function __construct(
        private ReservationService $reservationService,
        private WhatsAppLinkBuilder $waLinkBuilder,
    ) {}

    /**
     * GET /tienda/checkout — página formulario nombre + teléfono.
     * El listado de items lo provee Alpine.js desde localStorage.
     */
    public function checkout(): View
    {
        return view('shop.checkout');
    }

    /**
     * POST /tienda/reservar — crea Sale PENDING + decrementa stock + retorna
     * URL wa.me con mensaje pre-armado.
     *
     * Body JSON:
     * {
     *   "buyer_name": "Juan Pérez",
     *   "buyer_phone": "70012345",
     *   "items": [{ "product_id": 12, "qty": 1 }, ...]
     * }
     *
     * Response:
     * {
     *   "ok": true,
     *   "sale_id": 123,
     *   "invoice_number": "INV-...",
     *   "whatsapp_url": "https://wa.me/...?text=..."
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'buyer_name' => ['required', 'string', 'min:2', 'max:120'],
            'buyer_phone' => ['required', 'string', 'min:6', 'max:30', 'regex:/^[\d\s\+\-\(\)]+$/'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        try {
            $sale = $this->reservationService->createReservation(
                buyerName: $validated['buyer_name'],
                buyerPhone: $validated['buyer_phone'],
                items: $validated['items'],
            );

            $whatsappUrl = $this->waLinkBuilder->build($sale);

            // Notifica al admin vía Telegram (si está configurado) + ganchos futuros.
            WebReservationCreated::dispatch($sale);

            // Invalida contador del badge admin para que aparezca al instante.
            Cache::forget('shop.pending_web_count');

            return response()->json([
                'ok' => true,
                'sale_id' => $sale->id,
                'invoice_number' => $sale->invoice_number,
                'whatsapp_url' => $whatsappUrl,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\App\Exceptions\SaleException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Reservation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'buyer' => $validated['buyer_name'] ?? '?',
            ]);
            return response()->json([
                'ok' => false,
                'error' => 'No pudimos procesar la reserva. Intenta de nuevo en un momento.',
            ], 500);
        }
    }
}
