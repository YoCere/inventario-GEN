<?php

namespace App\Services\Telegram;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\TelegramConversation;
use App\Services\Messaging\TelegramService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BotReportHandler
{
    public function __construct(
        protected TelegramService $telegram,
    ) {}

    public function showMenu(string $chatId): void
    {
        $conversation = TelegramConversation::getOrCreate($chatId);
        $conversation->update([
            'step' => 'reportes:menu',
            'data' => [],
            'expires_at' => now()->addMinutes(5),
        ]);

        $message = "📊 <b>Reportes del negocio</b>\n\n";
        $message .= "Elige una opción:\n\n";
        $message .= "1️⃣ Resumen del día (ventas, compras, ganancia)\n";
        $message .= "2️⃣ Top 5 productos más vendidos (mes)\n";
        $message .= "3️⃣ Stock crítico (bajo mínimo)\n";
        $message .= "4️⃣ Stock muerto (sin movimiento 30d)\n";
        $message .= "5️⃣ Ganancia del mes\n";
        $message .= "6️⃣ Libro diario (últimas 10 acciones)\n";
        $message .= "7️⃣ Flujo de caja del mes\n";
        $message .= "8️⃣ Cuentas por pagar (compras pendientes)\n\n";
        $message .= "<i>Escribe el número o /cancelar para salir</i>";

        $this->telegram->sendMessage($chatId, $message);
    }

    public function handle(string $chatId, array $message): void
    {
        $text = trim($message['text'] ?? '');
        $conversation = TelegramConversation::getOrCreate($chatId);

        if (strtolower($text) === '/cancelar') {
            $conversation->delete();
            $this->telegram->sendMessage($chatId, "❌ Reportes cancelado.");
            return;
        }

        match ($text) {
            '1' => $this->resumenDelDia($chatId),
            '2' => $this->topProductosVendidos($chatId),
            '3' => $this->stockCritico($chatId),
            '4' => $this->stockMuerto($chatId),
            '5' => $this->gananciaDelMes($chatId),
            '6' => $this->libroDiario($chatId),
            '7' => $this->flujoCajaMes($chatId),
            '8' => $this->cuentasPorPagar($chatId),
            default => $this->telegram->sendMessage($chatId, "❌ Opción inválida. Escribe un número del 1 al 8 o /cancelar."),
        };

        // Keep menu alive after each report
        if (in_array($text, ['1','2','3','4','5','6','7','8'])) {
            $conversation->update([
                'step' => 'reportes:menu',
                'expires_at' => now()->addMinutes(5),
            ]);
            $this->telegram->sendMessage($chatId, "📊 Escribe otro número para más reportes o /cancelar para salir.");
        }
    }

    protected function resumenDelDia(string $chatId): void
    {
        $today = now()->toDateString();

        $sales = Sale::whereDate('sale_date', $today)
            ->where('status', 'completed')
            ->get();

        $purchases = Purchase::whereDate('purchase_date', $today)
            ->whereIn('status', ['received', 'paid'])
            ->get();

        $ventasTotal = $sales->sum('total');
        $ventasCount = $sales->count();
        $comprasTotal = $purchases->sum('total');
        $comprasCount = $purchases->count();
        $costoVentas = SaleItem::whereIn('sale_id', $sales->pluck('id'))
            ->sum(DB::raw('cost_price * quantity'));
        $gananciaBruta = $ventasTotal - $costoVentas;
        $ticketPromedio = $ventasCount > 0 ? $ventasTotal / $ventasCount : 0;

        $msg = "📅 <b>Resumen del día</b> ({$today})\n\n";
        $msg .= "💰 <b>Ventas:</b> " . format_money($ventasTotal) . " ({$ventasCount} trans.)\n";
        $msg .= "🎫 Ticket promedio: " . format_money($ticketPromedio) . "\n\n";
        $msg .= "📦 <b>Compras:</b> " . format_money($comprasTotal) . " ({$comprasCount} trans.)\n\n";
        $msg .= "💵 <b>Costo de ventas:</b> " . format_money($costoVentas) . "\n";
        $msg .= "📈 <b>Ganancia bruta:</b> " . format_money($gananciaBruta);

        $this->telegram->sendMessage($chatId, $msg);
    }

    protected function topProductosVendidos(string $chatId): void
    {
        $start = now()->startOfMonth();

        $top = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', 'completed')
            ->where('sales.sale_date', '>=', $start)
            ->select(
                'products.name',
                DB::raw('SUM(sale_items.quantity) as total_qty'),
                DB::raw('SUM(sale_items.subtotal) as total_revenue')
            )
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_qty')
            ->limit(5)
            ->get();

        if ($top->isEmpty()) {
            $this->telegram->sendMessage($chatId, "📭 No hay ventas este mes todavía.");
            return;
        }

        $msg = "🏆 <b>Top 5 productos vendidos (mes)</b>\n\n";
        foreach ($top as $idx => $row) {
            $pos = $idx + 1;
            $rev = format_money($row->total_revenue);
            $msg .= "{$pos}. <b>{$row->name}</b>\n";
            $msg .= "   {$row->total_qty} uds. - {$rev}\n";
        }

        $this->telegram->sendMessage($chatId, $msg);
    }

    protected function stockCritico(string $chatId): void
    {
        $products = Product::where('is_active', true)
            ->whereRaw('quantity <= min_stock')
            ->orderBy('quantity')
            ->limit(15)
            ->get();

        if ($products->isEmpty()) {
            $this->telegram->sendMessage($chatId, "✅ Todos los productos tienen stock suficiente.");
            return;
        }

        $msg = "⚠️ <b>Stock crítico</b> ({$products->count()} productos)\n\n";
        foreach ($products as $p) {
            $unit = $p->unit?->symbol ?? 'uni';
            $msg .= "• <b>{$p->name}</b>\n";
            $msg .= "   {$p->quantity} {$unit} (mín: {$p->min_stock})\n";
        }

        $this->telegram->sendMessage($chatId, $msg);
    }

    protected function stockMuerto(string $chatId): void
    {
        $thirtyDaysAgo = now()->subDays(30);

        // Products with stock > 0 but no sales in last 30 days
        $soldProductIds = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'completed')
            ->where('sales.sale_date', '>=', $thirtyDaysAgo)
            ->distinct()
            ->pluck('sale_items.product_id');

        $dead = Product::where('is_active', true)
            ->where('quantity', '>', 0)
            ->whereNotIn('id', $soldProductIds)
            ->orderByDesc('quantity')
            ->limit(10)
            ->get();

        if ($dead->isEmpty()) {
            $this->telegram->sendMessage($chatId, "✅ No hay productos sin movimiento (todos vendieron en últimos 30d).");
            return;
        }

        $msg = "💀 <b>Stock muerto</b> (sin venta hace 30d)\n\n";
        foreach ($dead as $p) {
            $unit = $p->unit?->symbol ?? 'uni';
            $capitalInmovilizado = $p->purchase_price * $p->quantity;
            $msg .= "• <b>{$p->name}</b>\n";
            $msg .= "   Stock: {$p->quantity} {$unit} - Capital: " . format_money($capitalInmovilizado) . "\n";
        }

        $this->telegram->sendMessage($chatId, $msg);
    }

    protected function gananciaDelMes(string $chatId): void
    {
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        $sales = Sale::where('status', 'completed')
            ->whereBetween('sale_date', [$start, $end])
            ->get();

        $ventasTotal = $sales->sum('total');
        $costoVentas = SaleItem::whereIn('sale_id', $sales->pluck('id'))
            ->sum(DB::raw('cost_price * quantity'));
        $gananciaBruta = $ventasTotal - $costoVentas;
        $margen = $ventasTotal > 0 ? ($gananciaBruta / $ventasTotal) * 100 : 0;

        $msg = "📈 <b>Ganancia del mes</b> (" . $start->format('M Y') . ")\n\n";
        $msg .= "💰 Ventas: " . format_money($ventasTotal) . "\n";
        $msg .= "💸 Costo: " . format_money($costoVentas) . "\n";
        $msg .= "📊 <b>Ganancia bruta:</b> " . format_money($gananciaBruta) . "\n";
        $msg .= "📐 Margen: " . number_format($margen, 1) . "%\n";
        $msg .= "🛒 Transacciones: {$sales->count()}";

        $this->telegram->sendMessage($chatId, $msg);
    }

    protected function libroDiario(string $chatId): void
    {
        $logs = AuditLog::with('user')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        if ($logs->isEmpty()) {
            $this->telegram->sendMessage($chatId, "📭 No hay registros en el libro diario.");
            return;
        }

        $msg = "📖 <b>Libro diario - últimas 10 acciones</b>\n\n";
        foreach ($logs as $log) {
            $time = $log->created_at->format('d/m H:i');
            $user = $log->user?->name ?? 'Sistema';
            $action = str_replace(['.', '_'], [' › ', ' '], $log->action);
            $msg .= "🔸 <b>{$time}</b> · {$user}\n";
            $msg .= "   {$action}\n";
        }

        $this->telegram->sendMessage($chatId, $msg);
    }

    protected function flujoCajaMes(string $chatId): void
    {
        $start = now()->startOfMonth();

        $ingresos = Sale::where('status', 'completed')
            ->where('sale_date', '>=', $start)
            ->sum('total');

        $egresos = Purchase::whereIn('status', ['paid'])
            ->where('purchase_date', '>=', $start)
            ->sum('total');

        $flujo = $ingresos - $egresos;

        $msg = "💵 <b>Flujo de caja del mes</b> (" . $start->format('M Y') . ")\n\n";
        $msg .= "⬆️ Ingresos (ventas): " . format_money($ingresos) . "\n";
        $msg .= "⬇️ Egresos (compras pagadas): " . format_money($egresos) . "\n\n";
        $msg .= ($flujo >= 0 ? "✅" : "⚠️") . " <b>Flujo neto:</b> " . format_money($flujo);

        $this->telegram->sendMessage($chatId, $msg);
    }

    protected function cuentasPorPagar(string $chatId): void
    {
        $pendientes = Purchase::with('supplier')
            ->whereIn('status', ['ordered', 'received'])
            ->orderBy('due_date')
            ->limit(10)
            ->get();

        if ($pendientes->isEmpty()) {
            $this->telegram->sendMessage($chatId, "✅ No hay compras pendientes de pago.");
            return;
        }

        $total = $pendientes->sum('total');

        $msg = "💳 <b>Cuentas por pagar</b> ({$pendientes->count()})\n";
        $msg .= "Total adeudado: <b>" . format_money($total) . "</b>\n\n";

        foreach ($pendientes as $p) {
            $supplier = $p->supplier?->name ?? 'Sin proveedor';
            $due = $p->due_date ? Carbon::parse($p->due_date)->format('d/m/Y') : 'sin fecha';
            $overdue = $p->due_date && Carbon::parse($p->due_date)->isPast() ? ' ⚠️ VENCIDO' : '';
            $msg .= "• <b>{$p->invoice_number}</b> - {$supplier}\n";
            $msg .= "   " . format_money($p->total) . " - Vence: {$due}{$overdue}\n";
        }

        $this->telegram->sendMessage($chatId, $msg);
    }
}
