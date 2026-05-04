<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Setting;
use App\Models\Sale;
use App\Models\Product;
use App\Jobs\SendTelegramMessage;
use Illuminate\Support\Facades\DB;

class SendDailySummaryCommand extends Command
{
    protected $signature = 'telegram:daily-summary';
    protected $description = 'Send daily sales summary to Telegram';

    public function handle(): void
    {
        if (!Setting::get('telegram_enabled') || !Setting::get('telegram_notify_daily')) {
            return;
        }

        $chatId = Setting::get('telegram_admin_chat_id');
        if (!$chatId) {
            return;
        }

        $today = now()->toDate();
        $message = $this->buildMessage($today);

        SendTelegramMessage::dispatch($chatId, $message);
        $this->info('Daily summary sent to Telegram');
    }

    private function buildMessage($date): string
    {
        $sales = Sale::whereDate('created_at', $date)
            ->where('status', 'completed')
            ->get();

        $totalSales = $sales->sum('total');
        $countSales = $sales->count();

        // Top 3 products sold today
        $topProducts = DB::table('sale_items')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->whereDate('sale_items.created_at', $date)
            ->selectRaw('products.name, products.id, sum(sale_items.quantity) as qty, products.unit_id')
            ->groupBy('sale_items.product_id', 'products.name', 'products.id', 'products.unit_id')
            ->orderByDesc('qty')
            ->limit(3)
            ->get()
            ->map(function ($item) {
                $product = Product::find($item->id);
                $unitSymbol = $product->unit?->symbol ?? 'uni';
                return "{$item->name} — {$item->qty} {$unitSymbol}";
            });

        // Low stock products
        $lowStockCount = Product::where('quantity', '<=', DB::raw('min_stock'))
            ->where('is_active', true)
            ->count();

        $lowStockProducts = Product::where('quantity', '<=', DB::raw('min_stock'))
            ->where('is_active', true)
            ->limit(5)
            ->get()
            ->map(fn ($p) => "• {$p->name}: {$p->quantity} / mín {$p->min_stock}");

        // Build message
        $dateStr = $date->format('d/m/Y');
        $formattedTotal = number_format($totalSales / 100, 2);

        $message = "📊 <b>Resumen del día — {$dateStr}</b>\n\n";
        $message .= "💰 Ventas: {$countSales} ({$formattedTotal})\n";

        if ($topProducts->isNotEmpty()) {
            $message .= "📦 <b>Productos más vendidos:</b>\n";
            foreach ($topProducts as $idx => $product) {
                $message .= ($idx + 1) . ". {$product}\n";
            }
        }

        if ($lowStockCount > 0) {
            $message .= "\n⚠️ <b>Stock crítico ({$lowStockCount} productos):</b>\n";
            foreach ($lowStockProducts as $product) {
                $message .= "{$product}\n";
            }
        }

        return $message;
    }
}
