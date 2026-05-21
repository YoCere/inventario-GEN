<?php

namespace App\Services\Agent\Tools;

use App\Models\Category;
use App\Models\TelegramConversation;
use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;
use App\Services\Messaging\TelegramService;

class StartProductCreationTool extends BaseTool
{
    public function __construct(private TelegramService $telegram) {}

    public function name(): string
    {
        return 'start_product_creation';
    }

    public function description(): string
    {
        return 'Inicia flujo de creación de producto. Llama cuando el usuario quiera crear/registrar/agregar un producto. Extrae los campos del mensaje del usuario sin importar el orden: nombre, categoría, precio_compra, precio_venta, cantidad. Pasa todos los que detectes; el flujo pedirá los que falten.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name'           => ['type' => 'string',  'description' => 'Nombre del producto'],
                'category'       => ['type' => 'string',  'description' => 'Categoría (puede ser parcial)'],
                'purchase_price' => ['type' => 'number',  'description' => 'Precio de compra en bolivianos'],
                'selling_price'  => ['type' => 'number',  'description' => 'Precio de venta en bolivianos'],
                'quantity'       => ['type' => 'integer', 'description' => 'Stock inicial'],
            ],
        ];
    }

    public function execute(array $input, AgentContext $context): array
    {
        $data = [];

        // ── Extract & normalize fields ───────────────────────────────────────
        if (!empty($input['name'])) {
            $data['nombre'] = trim($input['name']);
        }

        $categoryStatus = null; // 'found' | 'pending' | null
        if (!empty($data['nombre']) && !empty($input['category'])) {
            $catName = trim($input['category']);
            $cat = Category::whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($catName) . '%'])->first();
            if ($cat) {
                $data['categoria_id']     = $cat->id;
                $data['categoria_nombre'] = $cat->name;
                $categoryStatus           = 'found';
            } else {
                $data['categoria_pending'] = $catName;
                $categoryStatus            = 'pending';
            }
        }

        if (!empty($data['categoria_id']) && isset($input['purchase_price']) && $input['purchase_price'] > 0) {
            $data['precio_compra'] = (int) round($input['purchase_price'] * 100);
        }

        if (!empty($data['precio_compra']) && isset($input['selling_price']) && $input['selling_price'] > 0) {
            $data['precio_venta'] = (int) round($input['selling_price'] * 100);
        }

        if (!empty($data['precio_venta']) && isset($input['quantity']) && $input['quantity'] >= 0) {
            $data['cantidad'] = (int) $input['quantity'];
        }

        // ── Determine next step ──────────────────────────────────────────────
        $nextStep = match (true) {
            empty($data['nombre'])           => 'nuevo:nombre',
            $categoryStatus === 'pending'    => 'nuevo:categoria', // user must confirm sí/no
            empty($data['categoria_id'])     => 'nuevo:categoria',
            empty($data['precio_compra'])    => 'nuevo:precio_compra',
            empty($data['precio_venta'])     => 'nuevo:precio_venta',
            !isset($data['cantidad'])        => 'nuevo:cantidad',
            default                          => 'nuevo:foto',
        };

        // ── Build summary of what was registered ─────────────────────────────
        $summary = $this->buildSummary($data, $categoryStatus);

        // ── Build prompt for next missing field ──────────────────────────────
        $prompt = $this->buildPrompt($nextStep, $data, $categoryStatus);

        $finalMessage = $summary
            ? $summary . "\n\n" . $prompt
            : $prompt;

        // ── Save state & send ────────────────────────────────────────────────
        $conversation = TelegramConversation::getOrCreate($context->chatId);
        $conversation->update([
            'step'       => $nextStep,
            'data'       => $data,
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->telegram->sendMessage($context->chatId, $finalMessage);

        return [
            'status'    => 'ok',
            'next_step' => $nextStep,
            'prefilled' => array_keys($data),
        ];
    }

    private function buildSummary(array $data, ?string $categoryStatus): string
    {
        $lines = [];
        if (!empty($data['nombre'])) {
            $lines[] = "• Nombre: <b>{$data['nombre']}</b>";
        }
        if (!empty($data['categoria_id'])) {
            $lines[] = "• Categoría: <b>{$data['categoria_nombre']}</b>";
        }
        if (!empty($data['precio_compra'])) {
            $lines[] = "• Precio compra: <b>" . number_format($data['precio_compra'] / 100, 2) . "</b>";
        }
        if (!empty($data['precio_venta'])) {
            $lines[] = "• Precio venta: <b>" . number_format($data['precio_venta'] / 100, 2) . "</b>";
        }
        if (isset($data['cantidad'])) {
            $lines[] = "• Stock inicial: <b>{$data['cantidad']}</b>";
        }

        if (empty($lines)) {
            return '';
        }

        return "📝 <b>Registrando producto</b>\n\n✅ Datos detectados:\n" . implode("\n", $lines);
    }

    private function buildPrompt(string $step, array $data, ?string $categoryStatus): string
    {
        return match ($step) {
            'nuevo:nombre' =>
                "❓ <b>Falta:</b> nombre del producto.\n\nEscribe el nombre. (/cancelar para salir)",

            'nuevo:categoria' => $categoryStatus === 'pending'
                ? "❓ <b>Falta confirmar categoría:</b>\n\nNo encontré \"<b>{$data['categoria_pending']}</b>\".\n¿Crear esta categoría? <i>Responde sí/no o escribe otro nombre.</i>"
                : "❓ <b>Falta:</b> categoría.\n\n" . $this->buildCategoryPrompt(),

            'nuevo:precio_compra' =>
                "❓ <b>Falta:</b> precio de compra.\n\nEscribe el precio (ej: 25.50)",

            'nuevo:precio_venta' =>
                "❓ <b>Falta:</b> precio de venta.\n\nEscribe el precio.",

            'nuevo:cantidad' =>
                "❓ <b>Falta:</b> cantidad inicial en stock.\n\nEscribe un número entero (ej: 10)",

            'nuevo:foto' =>
                "📸 <b>Falta:</b> foto del producto.\n\nEnvía una foto o escribe <b>omitir</b> si no tienes.",

            default =>
                "Continuar con la creación del producto.",
        };
    }

    private function buildCategoryPrompt(): string
    {
        $categories = Category::orderBy('name')->limit(12)->get();
        $total = Category::count();

        if ($categories->isEmpty()) {
            return "No hay categorías aún. Escribe el nombre de la nueva.";
        }

        $msg = "";
        foreach ($categories as $idx => $cat) {
            $msg .= ($idx + 1) . ". {$cat->name}\n";
        }
        if ($total > 12) {
            $msg .= "... ({$total} en total)\n";
        }
        $msg .= "\nEscribe el <b>número</b> o el <b>nombre</b>.\n";
        $msg .= "<i>Si no existe, escríbela y te pregunto si crear.</i>";
        return $msg;
    }
}
