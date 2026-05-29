<?php

namespace App\Services\Telegram;

use App\Models\TelegramConversation;
use App\Models\Sale;
use App\Services\Messaging\TelegramService;
use App\Services\SaleService;
use Illuminate\Support\Facades\Log;

class BotRefundHandler
{
    public function __construct(
        protected TelegramService $telegram,
        protected SaleService $saleService,
    ) {}

    public function handle(string $chatId, array $message): void
    {
        $conversation = TelegramConversation::getOrCreate($chatId);
        $text = trim($message['text'] ?? '');

        // Check for cancel command
        if (strtolower($text) === '/cancelar' || strtolower($text) === 'cancel') {
            $conversation->delete();
            $this->telegram->sendMessage($chatId, "❌ Devolución cancelada.");
            return;
        }

        if ($conversation->step === 'devolver:seleccionar') {
            $this->handleSaleSelection($chatId, $conversation, $text);
        }
    }

    public function start(string $chatId): void
    {
        // Get latest sales from today
        $today = now()->toDateString();
        $sales = Sale::whereDate('created_at', $today)
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->with(['items.product'])
            ->limit(10)
            ->get();

        if ($sales->isEmpty()) {
            $this->telegram->sendMessage(
                $chatId,
                "❌ No hay ventas registradas hoy para devolver."
            );
            return;
        }

        // Store sales in conversation
        $conversation = TelegramConversation::getOrCreate($chatId);
        $salesData = $sales->map(function ($sale) {
            return [
                'id' => $sale->id,
                'invoice' => $sale->invoice_number,
                'time' => $sale->created_at->format('H:i'),
                'total' => $sale->total,
                'items' => $sale->items->map(fn ($item) => [
                    'product_name' => $item->product?->name ?? '(producto eliminado)',
                    'quantity' => $item->quantity,
                ])->toArray(),
            ];
        })->toArray();

        $conversation->update([
            'step' => 'devolver:seleccionar',
            'data' => ['sales' => $salesData],
            'expires_at' => now()->addMinutes(10),
        ]);

        $message = "📋 <b>Devoluciones - Últimas ventas de hoy</b>\n\n";
        foreach ($salesData as $idx => $sale) {
            $items = implode(', ', array_map(
                fn ($item) => "{$item['quantity']}x {$item['product_name']}",
                $sale['items']
            ));
            $total = number_format($sale['total'] / 100, 2);
            $message .= ($idx + 1) . ". <b>{$sale['time']}</b> - {$items}\n";
            $message .= "   Total: Bs {$total} ({$sale['invoice']})\n\n";
        }
        $message .= "Escribe el número para devolver\n";
        $message .= "(Escribe /cancelar para salir)";

        $this->telegram->sendMessage($chatId, $message);
    }

    private function handleSaleSelection(string $chatId, TelegramConversation $conversation, string $input): void
    {
        $salesData = $conversation->data['sales'] ?? [];
        $index = (int) $input - 1;

        Log::info('Refund selection', ['chatId' => $chatId, 'index' => $index, 'total' => count($salesData)]);

        if ($index < 0 || $index >= count($salesData)) {
            $this->telegram->sendMessage($chatId, "❌ Número inválido. Escribe un número entre 1 y " . count($salesData));
            return;
        }

        $selectedSale = $salesData[$index];
        $saleId = $selectedSale['id'] ?? null;

        if (!$saleId) {
            $this->telegram->sendMessage($chatId, "❌ Venta no encontrada.");
            $conversation->delete();
            return;
        }

        $sale = Sale::find($saleId);

        if (!$sale) {
            $this->telegram->sendMessage($chatId, "❌ Venta no encontrada en BD.");
            $conversation->delete();
            return;
        }

        // Show confirmation before refunding
        $this->showConfirm($chatId, $conversation, $sale, $selectedSale);
    }

    private function showConfirm(string $chatId, TelegramConversation $conversation, Sale $sale, array $saleData): void
    {
        $items = implode("\n", array_map(
            fn ($item) => "• {$item['quantity']}x {$item['product_name']}",
            $saleData['items']
        ));
        $total = number_format($sale->total / 100, 2);

        $message = "📋 <b>Confirmación de devolución</b>\n\n";
        $message .= "Factura: {$sale->invoice_number}\n";
        $message .= "Hora: {$saleData['time']}\n\n";
        $message .= "<b>Productos:</b>\n{$items}\n\n";
        $message .= "Total a revertir: <b>Bs {$total}</b>\n\n";
        $message .= "¿Confirmar devolución?\n";
        $message .= "1️⃣ Sí\n";
        $message .= "2️⃣ No";

        $conversation->update([
            'step' => 'devolver:confirmar',
            'data' => ['sale_id' => $sale->id, 'sale_data' => $saleData],
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->telegram->sendMessage($chatId, $message);
    }

    public function confirmRefund(string $chatId, TelegramConversation $conversation): void
    {
        $saleId = $conversation->data['sale_id'] ?? null;
        $saleData = $conversation->data['sale_data'] ?? [];

        if (!$saleId) {
            $this->telegram->sendMessage($chatId, "❌ Error: Venta no encontrada.");
            $conversation->delete();
            return;
        }

        $sale = Sale::find($saleId);

        if (!$sale) {
            $this->telegram->sendMessage($chatId, "❌ Error: Venta no encontrada en BD.");
            $conversation->delete();
            return;
        }

        try {
            // Cancel sale (reverses stock, voids finance)
            $reason = 'Devolución vía Telegram - ' . now()->format('H:i:s');
            $this->saleService->cancelSale($sale, $reason);

            $conversation->delete();

            $total = number_format($sale->total / 100, 2);
            $this->telegram->sendMessage(
                $chatId,
                "✅ <b>¡Devolución procesada!</b>\n\n" .
                "Factura: {$sale->invoice_number}\n" .
                "Monto revertido: <b>Bs {$total}</b>\n\n" .
                "Stock actualizado. Listo."
            );
        } catch (\Exception $e) {
            Log::error('Refund processing error', [
                'error' => $e->getMessage(),
                'sale_id' => $saleId,
            ]);
            $conversation->delete();
            $this->telegram->sendMessage(
                $chatId,
                "❌ Error al procesar devolución.\n\n" .
                "Error: " . $e->getMessage()
            );
        }
    }
}
