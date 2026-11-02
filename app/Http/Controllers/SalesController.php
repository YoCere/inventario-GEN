<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\DTOs\SaleData;
use Illuminate\Http\Request;
use App\Services\Metrics\TimingRecorder;
use App\Services\SaleService;
use App\Exceptions\SaleException;
use App\Support\Metrics\TimedActivity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\StoreSaleRequest;

class SalesController extends Controller
{
    /**
     * Desde cuándo se está atendiendo al cliente de la pantalla de cobro.
     *
     * Vive en la sesión y no en la pantalla porque la pantalla de cobro no se
     * recarga entre una venta y la siguiente: el vendedor cobra, la pantalla se
     * limpia sola y sigue atendiendo. Si el reloj viviera en el navegador habría
     * que tocar esa pantalla, y si viviera en la base habría una fila abierta por
     * cada vendedor que algún día hay que limpiar.
     */
    private const RELOJ_DEL_COBRO = 'pos.atendiendo_desde';

    public function index()
    {
        return view('sales.index');
    }

    public function create()
    {
        // El reloj arranca cuando la pantalla de cobro queda lista para atender.
        session([self::RELOJ_DEL_COBRO => now()->toIso8601String()]);

        return view('sales.create');
    }

    public function store(StoreSaleRequest $request, SaleService $saleService)
    {
        try {
            $validated = $request->validated();
            $validated['created_by'] = Auth::id();

            $saleData = SaleData::fromArray($validated);

            $sale = $saleService->createSale($saleData);

            // La venta ya está cobrada y guardada. Recién ahora se mide.
            $this->anotarTiempoDelCobro($request);

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Venta creada correctamente',
                    'data' => $sale,
                    'print_url' => route('sales.print', $sale->id),
                    'redirect' => route('sales.create')
                ], 201);
            }

            return redirect()->route('sales.create')
                ->with('success', 'Venta creada correctamente.');

        } catch (SaleException $e) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $e->getMessage()], 400);
            }
            return back()->with('error', $e->getMessage())->withInput();

        } catch (\Exception $e) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $e->getMessage()], 400);
            }
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    /**
     * Anota cuánto se tardó en cobrar, sin poder arruinar el cobro.
     *
     * Se llama con la venta YA guardada, y todo el bloque va dentro de un
     * try/catch: es plata real: ninguna estadística puede hacer que una venta
     * cobrada termine en pantalla como un error.
     *
     * De dónde sale el arranque del reloj, en este orden:
     *  1. `atendido_desde` que mande la pantalla de cobro, si algún día lo manda
     *     (es más preciso: sabe cuándo entró el primer producto al carrito).
     *  2. La sesión, puesta al abrir la pantalla de cobro o al terminar la venta
     *     anterior. Para la segunda venta en adelante, el reloj arranca cuando
     *     la pantalla quedó libre, que es cuando empieza el próximo cliente.
     *
     * Sin ninguno de los dos no se mide: medio dato es peor que ninguno.
     */
    private function anotarTiempoDelCobro(Request $request): void
    {
        try {
            $delNavegador = $request->input('atendido_desde');
            $inicio = is_string($delNavegador) && $delNavegador !== ''
                ? $delNavegador
                : session(self::RELOJ_DEL_COBRO);

            app(TimingRecorder::class)->registrar(
                TimedActivity::VENTA,
                TimedActivity::CANAL_WEB,
                Auth::id(),
                $inicio,
            );

            // La próxima venta se mide desde ahora: la pantalla ya está libre.
            session([self::RELOJ_DEL_COBRO => now()->toIso8601String()]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo anotar el tiempo del cobro', ['error' => $e->getMessage()]);
        }
    }

    public function show(Sale $sale)
    {
        $sale->load(['items.product.unit', 'customer', 'creator']);
        return view('sales.show', compact('sale'));
    }

    public function destroy(Request $request, Sale $sale, SaleService $saleService)
    {
        // Lógica de autorización por estado de venta
        if ($sale->status === \App\Enums\SaleStatus::COMPLETED) {
            // Solo admin puede cancelar ventas completadas
            abort_if(!auth()->user()->isAdmin(), 403);
        } elseif ($sale->status === \App\Enums\SaleStatus::PENDING) {
            // Staff puede cancelar su propia venta pendiente
            if ($sale->created_by !== auth()->id() && !auth()->user()->isAdmin()) {
                abort(403, 'Solo puedes cancelar tus propias ventas.');
            }
        }

        try {
            $reason = $request->input('reason');
            $saleService->cancelSale($sale, $reason);
            return redirect()->route('sales.index')->with('success', 'Venta cancelada correctamente.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function print(Sale $sale)
    {
        $sale->load(['items.product.unit', 'customer', 'creator']);
        return view('sales.print', compact('sale'));
    }

    public function restore(Sale $sale, SaleService $saleService)
    {
        abort_if(!auth()->user()->isAdmin(), 403);

        try {
            $saleService->restoreSale($sale);
            return redirect()->back()->with('success', 'Venta restaurada a reservado.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function complete(Request $request, Sale $sale, SaleService $saleService)
    {
        // Solo el creador o un admin puede completar la venta.
        if (auth()->id() !== $sale->created_by && ! auth()->user()->isAdmin()) {
            abort(403, 'Solo el vendedor asignado o un administrador puede completar esta venta.');
        }

        try {
            $paymentData = $request->only(['cash_received', 'change']);

            $saleService->completeSale($sale, $paymentData);

            return redirect()->back()->with('success', 'Venta marcada como completada.');

        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
