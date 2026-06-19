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

    public function webExposed(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'start_product_creation';
    }

    public function description(): string
    {
        return <<<DESC
Inicia el flujo de creación de producto. Llama esta herramienta cuando el usuario
quiera crear/registrar/agregar un producto al inventario.

IMPORTANTE: extrae TODOS los campos que detectes en el mensaje del usuario,
SIN IMPORTAR EL ORDEN y SIN DEPENDER DE QUE OTROS CAMPOS ESTÉN PRESENTES.
Pasa cada campo de manera independiente — el flujo solo pedirá al usuario los
que falten. Nunca omitas un campo solo porque otro no está claro.

Ejemplos de extracción:

Usuario: "Quiero registrar un producto llamado Labubu, categoría juguete,
10 de precio compra, 20 de venta, cantidad 100"
→ name="Labubu", category="juguete", purchase_price=10, selling_price=20,
   quantity=100

Usuario: "Crear producto mouse gamer, vale 50 la compra y 80 la venta"
→ name="mouse gamer", purchase_price=50, selling_price=80
   (sin category ni quantity — los pedirá el flujo)

Usuario: "Nuevo producto cable USB de la categoría electrónica, 30 unidades"
→ name="cable USB", category="electrónica", quantity=30
   (sin precios — los pedirá el flujo)
DESC;
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
        // ── Cargar estado previo (si el usuario ya estaba en flujo, no perder
        //    lo que ya había aportado en turnos anteriores). ──────────────────
        $conversation = TelegramConversation::getOrCreate($context->chatId);
        $existing = is_array($conversation->data) ? $conversation->data : [];
        $data = $existing;

        // ── Extracción INDEPENDIENTE por campo (sin cascade). Cualquier campo
        //    detectado por la IA se persiste, aunque otros no estén presentes. ─

        if (!empty($input['name'])) {
            $data['nombre'] = trim($input['name']);
        }

        $categoryStatus = null; // 'found' | 'pending' | null
        if (!empty($input['category'])) {
            $catName = trim($input['category']);
            $cat = Category::whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($catName) . '%'])->first();
            if ($cat) {
                $data['categoria_id']     = $cat->id;
                $data['categoria_nombre'] = $cat->name;
                unset($data['categoria_pending']);
                $categoryStatus           = 'found';
            } else {
                $data['categoria_pending'] = $catName;
                $categoryStatus            = 'pending';
            }
        }

        if (isset($input['purchase_price']) && $input['purchase_price'] > 0) {
            $data['precio_compra'] = (int) round($input['purchase_price'] * 100);
        }

        if (isset($input['selling_price']) && $input['selling_price'] > 0) {
            $data['precio_venta'] = (int) round($input['selling_price'] * 100);
        }

        if (isset($input['quantity']) && $input['quantity'] >= 0) {
            $data['cantidad'] = (int) $input['quantity'];
        }

        // ── Determinar siguiente paso basado en lo que SIGUE faltando ────────
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
