<?php

namespace App\Services\Telegram;

use App\Models\TelegramConversation;
use App\Models\Product;
use App\Services\Messaging\TelegramService;
use App\Services\SaleService;
use App\Support\NumberParser;
use App\DTOs\SaleData;
use App\DTOs\SaleItemData;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use Illuminate\Support\Facades\Log;

class BotSaleHandler
{
    public function __construct(
        protected TelegramService $telegram,
        protected SaleService $saleService,
        protected BotAuthHandler $authHandler,
    ) {}

    public function handle(string $chatId, array $message): void
    {
        $conversation = TelegramConversation::getOrCreate($chatId);
        $text = trim($message['text'] ?? '');

        // Check for cancel command
        if (strtolower($text) === '/cancelar' || strtolower($text) === 'cancel') {
            $conversation->delete();
            $this->telegram->sendMessage($chatId, "❌ Venta cancelada.");
            return;
        }

        // Route to appropriate step
        if (str_starts_with($conversation->step, 'venta_rapida:')) {
            $this->handleVentaRapidaFlow($chatId, $conversation, $text);
        }
    }

    public function startQuickSale(string $chatId, Product $product): void
    {
        $conversation = TelegramConversation::getOrCreate($chatId);
        $conversation->update([
            'step' => 'venta_rapida:cantidad',
            'data' => [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_price' => $product->selling_price,
                'disponible' => $product->quantity,
            ],
            'expires_at' => now()->addMinutes(15),
        ]);

        $unit = $product->unit?->symbol ?? 'uni';
        $price = number_format($product->selling_price / 100, 2);

        $this->telegram->sendMessage(
            $chatId,
            "🛒 <b>Venta rápida: {$product->name}</b>\n\n" .
            "Disponible: <b>{$product->quantity} {$unit}</b>\n" .
            "Precio: <b>Bs {$price}</b>\n\n" .
            "¿Cuántos deseas vender? (número)\n" .
            "(Escribe /cancelar para salir)"
        );
    }

    private function handleVentaRapidaFlow(string $chatId, TelegramConversation $conversation, string $text): void
    {
        $step = $conversation->step;

        match ($step) {
            'venta_rapida:cantidad' => $this->askCantidad($chatId, $conversation, $text),
            'venta_rapida:descuento' => $this->askDescuento($chatId, $conversation, $text),
            'venta_rapida:metodo_pago' => $this->askMetodoPago($chatId, $conversation, $text),
            'venta_rapida:confirmar' => $this->confirm($chatId, $conversation, $text),
            default => $this->telegram->sendMessage($chatId, "❓ Estado desconocido. /cancelar y reintenta."),
        };
    }

    private function askCantidad(string $chatId, TelegramConversation $conversation, string $input): void
    {
        $cantidad = NumberParser::extractInt($input);
        $data = $conversation->data ?? [];

        if ($cantidad === null || $cantidad <= 0) {
            $this->telegram->sendMessage($chatId, "❌ Cantidad inválida. Ingresa un número mayor a 0 (ej: 40, cuarenta).");
            return;
        }

        if ($cantidad > $data['disponible']) {
            $this->telegram->sendMessage(
                $chatId,
                "❌ No hay suficiente stock. Disponible: {$data['disponible']}"
            );
            return;
        }

        $data['cantidad'] = $cantidad;
        $subtotal = ($data['product_price'] * $cantidad);
        $data['subtotal'] = $subtotal;

        $conversation->update([
            'step' => 'venta_rapida:descuento',
            'data' => $data,
            'expires_at' => now()->addMinutes(15),
        ]);

        $subtotalFormated = number_format($subtotal / 100, 2);
        $this->telegram->sendMessage(
            $chatId,
            "📊 Cantidad: <b>{$cantidad}</b>\n\n" .
            "Subtotal: <b>Bs {$subtotalFormated}</b>\n\n" .
            "¿Descuento? (o 'no')\n" .
            "Ej: <code>500</code> (Bs), <code>10%</code> (porcentaje), o <code>no</code>"
        );
    }

    private function askDescuento(string $chatId, TelegramConversation $conversation, string $input): void
    {
        $data = $conversation->data ?? [];
        $descuento = 0;

        $input = strtolower(trim($input));

        // Accept natural-language "no" variants from voice
        $negatives = ['no', 'nada', 'sin descuento', 'ninguno', 'ninguna', 'cero'];
        $isNegative = false;
        foreach ($negatives as $neg) {
            if (str_contains($input, $neg)) {
                $isNegative = true;
                break;
            }
        }

        if (!$isNegative) {
            // Parse descuento: puede ser "500" (Bs) o "10%" (porcentaje)
            if (str_contains($input, '%') || str_contains($input, 'porciento') || str_contains($input, 'por ciento')) {
                $clean = str_replace(['%', 'porciento', 'por ciento'], '', $input);
                $porcentaje = NumberParser::extractFloat($clean) ?? 0.0;
                $descuento = (int) round(($data['subtotal'] * $porcentaje) / 100);
            } else {
                $bs = NumberParser::extractFloat($input);
                if ($bs === null) {
                    $this->telegram->sendMessage($chatId, "❌ Descuento inválido. Ej: 500, 10%, o 'no'.");
                    return;
                }
                $descuento = (int) round($bs * 100);
            }

            // Validar que descuento no supere subtotal
            if ($descuento > $data['subtotal']) {
                $this->telegram->sendMessage(
                    $chatId,
                    "❌ Descuento no puede superar el subtotal (Bs " .
                    number_format($data['subtotal'] / 100, 2) . ")"
                );
                return;
            }
        }

        $data['descuento'] = $descuento;
        $total = $data['subtotal'] - $descuento;

        $conversation->update([
            'step' => 'venta_rapida:metodo_pago',
            'data' => $data,
            'expires_at' => now()->addMinutes(15),
        ]);

        $subtotalFormated = number_format($data['subtotal'] / 100, 2);
        $descuentoFormated = number_format($descuento / 100, 2);
        $totalFormated = number_format($total / 100, 2);

        $message = "💰 <b>Resumen</b>\n\n";
        $message .= "Subtotal: Bs {$subtotalFormated}\n";
        if ($descuento > 0) {
            $message .= "Descuento: -Bs {$descuentoFormated}\n";
        }
        $message .= "Total: <b>Bs {$totalFormated}</b>\n\n";
        $message .= "¿Método de pago?\n";
        $message .= "1️⃣ Efectivo\n";
        $message .= "2️⃣ Transferencia";

        $this->telegram->sendMessage($chatId, $message);
    }

    private function askMetodoPago(string $chatId, TelegramConversation $conversation, string $input): void
    {
        $data = $conversation->data ?? [];
        $input = strtolower(trim($input));

        // Match against keywords; tolerant of full sentences from voice
        $hasCash     = $input === '1' || str_contains($input, 'efectivo') || str_contains($input, 'cash')
                       || str_contains($input, 'contado') || str_contains($input, 'billete');
        $hasTransfer = $input === '2' || str_contains($input, 'transfer') || str_contains($input, 'banco')
                       || str_contains($input, 'qr') || str_contains($input, 'tigo') || str_contains($input, 'depósito')
                       || str_contains($input, 'deposito');

        $metodoPago = match (true) {
            $hasCash     => PaymentMethod::CASH,
            $hasTransfer => PaymentMethod::TRANSFER,
            default      => null,
        };

        if (!$metodoPago) {
            $this->telegram->sendMessage(
                $chatId,
                "❌ Método inválido. Responde: 1️⃣ Efectivo o 2️⃣ Transferencia"
            );
            return;
        }

        $data['metodo_pago'] = $metodoPago->value;

        $conversation->update([
            'step' => 'venta_rapida:confirmar',
            'data' => $data,
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->showConfirm($chatId, $conversation, $data);
    }

    private function showConfirm(string $chatId, TelegramConversation $conversation, array $data): void
    {
        $subtotalFormated = number_format($data['subtotal'] / 100, 2);
        $descuentoFormated = number_format($data['descuento'] / 100, 2);
        $totalFormated = number_format(($data['subtotal'] - $data['descuento']) / 100, 2);

        $message = "📋 <b>Confirmación de venta</b>\n\n";
        $message .= "Producto: <b>{$data['product_name']}</b>\n";
        $message .= "Cantidad: {$data['cantidad']}\n";
        $message .= "Subtotal: Bs {$subtotalFormated}\n";
        if ($data['descuento'] > 0) {
            $message .= "Descuento: -Bs {$descuentoFormated}\n";
        }
        $message .= "Total: <b>Bs {$totalFormated}</b>\n";
        $message .= "Pago: {$data['metodo_pago']}\n\n";
        $message .= "<b>¿Confirmar venta?</b>\n";
        $message .= "1️⃣ Sí\n";
        $message .= "2️⃣ No";

        $this->telegram->sendMessage($chatId, $message);
    }

    private function confirm(string $chatId, TelegramConversation $conversation, string $text): void
    {
        $input = strtolower(trim($text));

        if (!in_array($input, ['1', 'sí', 'si', 'yes'])) {
            $conversation->delete();
            $this->telegram->sendMessage($chatId, "❌ Venta cancelada.");
            return;
        }

        $data = $conversation->data ?? [];

        try {
            // Crear venta
            $saleData = SaleData::fromArray([
                'sale_date' => now(),
                'payment_method' => $data['metodo_pago'],
                'created_by' => $this->authHandler->getAuthenticatedUser($chatId)?->id ?? 1,
                'items' => [
                    [
                        'product_id' => $data['product_id'],
                        'quantity' => $data['cantidad'],
                        'unit_price' => $data['product_price'],
                        'discount' => 0,
                    ]
                ],
                'customer_id' => null,
                'status' => 'completed',
                'global_discount' => $data['descuento'],
                'notes' => 'Venta rápida vía Telegram',
                'cash_received' => $data['subtotal'] - $data['descuento'],
                'change' => 0,
            ]);

            $sale = $this->saleService->createSale($saleData);

            $conversation->delete();

            $totalFormated = number_format(($data['subtotal'] - $data['descuento']) / 100, 2);
            $this->telegram->sendMessage(
                $chatId,
                "✅ <b>¡Venta completada!</b>\n\n" .
                "Factura: {$sale->invoice_number}\n" .
                "Producto: {$data['product_name']}\n" .
                "Cantidad: {$data['cantidad']}\n" .
                "Total: <b>Bs {$totalFormated}</b>\n\n" .
                "Stock actualizado. Listo para siguiente venta."
            );
        } catch (\Exception $e) {
            Log::error('Quick sale creation error', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            $conversation->delete();
            $this->telegram->sendMessage(
                $chatId,
                "❌ Error al registrar la venta. Verifica el stock y vuelve a intentarlo."
            );
        }
    }

    public function tryQuickSell(string $chatId, string $text): bool
    {
        $cmd = app(\App\Services\Sales\SaleCommandParser::class)->parse($text);
        if ($cmd === null) {
            return false;
        }

        $user = app(\App\Services\Telegram\BotAuthHandler::class)->getAuthenticatedUser($chatId);
        if (! $user) {
            $this->telegram->sendMessage($chatId, "❌ Sesión no válida.");
            return true;
        }

        if ($cmd->position !== null) {
            return $this->sellByPosition($chatId, $cmd, $user);
        }

        $results = app(\App\Services\Messaging\ProductSearchService::class)
            ->searchProducts($cmd->productQuery, publicOnly: false);

        if ($results->isEmpty()) {
            $this->telegram->sendMessage($chatId, "❌ No encontré ningún producto para \"<i>{$cmd->productQuery}</i>\".");
            return true;
        }

        if ($results->count() > 1) {
            $this->promptDirectPick($chatId, $cmd, $results);
            return true;
        }

        $this->completeDirectSale($chatId, $results->first(), $cmd, $user->id);
        return true;
    }

    private function promptDirectPick(string $chatId, \App\DTOs\ParsedSaleCommand $cmd, $results): void
    {
        $ids = [];
        $msg = "📦 <b>¿Cuál?</b>\n\n";
        foreach ($results->take(6)->values() as $idx => $p) {
            $n = $idx + 1;
            $ids[(string) $n] = $p->id;
            $price = number_format($p->selling_price / 100, 2);
            $msg .= "{$n}. <b>{$p->name}</b> — Bs {$price}\n";
        }
        $msg .= "\n<i>Responde el número.</i>";

        $conv = TelegramConversation::getOrCreate($chatId);
        $conv->update([
            'step' => 'venta_directa:elegir',
            'data' => [
                'ids'         => $ids,
                'quantity'    => $cmd->quantity,
                'unit_price'  => $cmd->unitPriceCents,
                'total_price' => $cmd->totalPriceCents,
                'method'      => $cmd->method->value,
            ],
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->telegram->sendMessage($chatId, $msg);
    }

    public function handleDirectPick(string $chatId, TelegramConversation $conv, string $text): void
    {
        $data = $conv->data ?? [];
        $id = ($data['ids'] ?? [])[trim($text)] ?? null;
        if (! $id) {
            $this->telegram->sendMessage($chatId, "❌ Número no válido. Responde uno de la lista o /cancelar.");
            return;
        }

        $product = \App\Models\Product::find($id);
        $user = app(\App\Services\Telegram\BotAuthHandler::class)->getAuthenticatedUser($chatId);
        $conv->delete();

        if (! $product || ! $user) {
            $this->telegram->sendMessage($chatId, "❌ No pude completar la venta.");
            return;
        }

        $cmd = new \App\DTOs\ParsedSaleCommand(
            quantity: (int) ($data['quantity'] ?? 1),
            unitPriceCents: $data['unit_price'] ?? null,
            totalPriceCents: $data['total_price'] ?? null,
            method: \App\Enums\PaymentMethod::from($data['method'] ?? 'cash'),
            productQuery: null,
            position: null,
        );

        $this->completeDirectSale($chatId, $product, $cmd, $user->id);
    }

    private function completeDirectSale(string $chatId, \App\Models\Product $product, \App\DTOs\ParsedSaleCommand $cmd, int $actorId): void
    {
        $unitPriceCents = $cmd->unitPriceCents;
        if ($unitPriceCents === null && $cmd->totalPriceCents !== null) {
            $unitPriceCents = (int) round($cmd->totalPriceCents / max(1, $cmd->quantity));
        }

        try {
            $result = app(\App\Services\QuickSaleService::class)->sell(
                $product, $cmd->quantity, $unitPriceCents, $cmd->method, 0, $actorId
            );
        } catch (\RuntimeException $e) {
            $this->telegram->sendMessage($chatId, "❌ " . $e->getMessage());
            return;
        }

        $sale = $result['sale'];
        $total = number_format($sale->total / 100, 2);
        $metodo = $cmd->method === \App\Enums\PaymentMethod::TRANSFER ? 'transferencia' : 'contado';

        $msg = "✅ <b>Vendido</b>: {$cmd->quantity} × " . htmlspecialchars($product->name, ENT_QUOTES, 'UTF-8')
            . " = <b>Bs {$total}</b> ({$metodo})\n";
        if ($result['below_cost']) {
            $msg .= "⚠️ Vendido por debajo del costo.\n";
        }
        if ($result['price_capped']) {
            $msg .= "⚠️ El precio pedido superaba la lista; se cobró al precio de lista.\n";
        }
        $msg .= "Responde <b>/deshacer</b> para anular.";

        $this->telegram->sendMessage($chatId, $msg);
    }

    /**
     * TODO(Task 3): implementar venta por posición (ordinal / número de resultado
     * previo). Stub temporal para que tryQuickSell compile; Task 3 lo reemplaza.
     */
    private function sellByPosition(string $chatId, \App\DTOs\ParsedSaleCommand $cmd, \App\Models\User $user): bool
    {
        $this->telegram->sendMessage($chatId, "Posicional pendiente.");
        return true;
    }
}
