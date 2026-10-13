<?php

namespace App\Services\Agent\Tools;

use App\Models\Category;
use App\Models\TelegramConversation;
use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;
use App\Services\Messaging\TelegramService;
use App\Services\Telegram\BotProductHandler;
use App\Services\Telegram\PasosDelAlta;
use App\Services\Telegram\UnidadDeVenta;

class StartProductCreationTool extends BaseTool
{
    /**
     * Pedir que el producto quede destacado. Se busca en lo que la persona dijo,
     * sin depender de que el modelo haya pasado el campo: la dueña decidió que
     * si lo dice en el audio, el producto queda destacado, y eso no puede
     * quedar colgado de cuánto se acuerde el modelo de extraer.
     */
    private const DICE_DESTACADO = '/\b(destacad[oa]s?|destacar|resaltad[oa]|resaltar|favorit[oa])\b/u';

    public function __construct(
        private TelegramService $telegram,
        private UnidadDeVenta $unidades,
        private PasosDelAlta $pasos,
    ) {}

    public function webExposed(): bool
    {
        return false;
    }

    public function requiredPermission(): ?string
    {
        return 'products.manage';
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
                'unit'           => ['type' => 'string',  'description' => 'Cómo se vende: "caja", "unidad", "par", "docena", "kilo". Solo si lo dice ("se vende por caja", "viene en cajas").'],
                'purchase_price' => ['type' => 'number',  'description' => 'Precio de compra en bolivianos'],
                'selling_price'  => ['type' => 'number',  'description' => 'Precio de venta en bolivianos'],
                'quantity'       => ['type' => 'integer', 'description' => 'Stock inicial'],
                'min_stock'      => ['type' => 'integer', 'description' => 'Con cuántas unidades avisar para reponer ("avisame cuando queden 5" → 5). 0 si pide no ser avisado.'],
                'featured'       => ['type' => 'boolean', 'description' => 'true si pide destacar el producto en la tienda ("marcalo como destacado").'],
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

        // El alta dictada por voz termina en una revisión con botones: la
        // transcripción puede escribir mal el nombre o cambiar una cantidad, y
        // la persona tiene que poder corregirlo sin repetir todo el audio.
        // El alta escrita paso a paso sigue confirmándose como siempre.
        if ($context->source === 'audio') {
            $data['origen_audio'] = true;
        }

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

        // Si el audio ya dijo cómo se vende ("se vende por caja"), el flujo no
        // vuelve a preguntarlo. Lo que se dijo se busca entre las unidades que
        // existen; si no corresponde a ninguna, se pregunta igual.
        if (!empty($input['unit'])) {
            $unidad = $this->unidades->buscar((string) $input['unit']);

            if ($unidad) {
                $data['unidad_id']     = $unidad->id;
                $data['unidad_nombre'] = $unidad->name;
            }
        }

        if (isset($input['min_stock']) && (int) $input['min_stock'] >= 0) {
            $data['stock_minimo'] = (int) $input['min_stock'];
        }

        if (!empty($input['featured']) || $this->pidioDestacado($context->mensaje)) {
            $data['destacado'] = true;
        }

        // ── Determinar siguiente paso basado en lo que SIGUE faltando ────────
        // La lista de pasos es una sola para todo el alta (PasosDelAlta): cuando
        // cada entrada tenía la suya, el audio preguntaba cosas que el flujo
        // escrito ya no preguntaba.
        if ($categoryStatus !== 'pending') {
            $data = $this->pasos->conDefectos($data);
        }

        $nextStep = $categoryStatus === 'pending'
            ? 'nuevo:categoria' // user must confirm sí/no
            : $this->pasos->siguiente($data);

        // ── Build summary of what was registered ─────────────────────────────
        $summary = $this->buildSummary($data, $categoryStatus);

        // Los pasos que se contestan tocando (y el resumen final) los arma el
        // handler: los teclados viven ahí y no conviene tener dos versiones.
        if (in_array($nextStep, ['nuevo:unidad', 'nuevo:stock_minimo', 'nuevo:revisar'], true)) {
            if ($summary !== '') {
                $this->telegram->sendMessage($context->chatId, $summary);
            }

            app(BotProductHandler::class)->continuar($context->chatId, $conversation, $data);

            return [
                'status'    => 'ok',
                'next_step' => $nextStep,
                'prefilled' => array_keys($data),
            ];
        }

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

    /** "marcalo como destacado" en lo que se dictó, sin pasar por el modelo. */
    private function pidioDestacado(?string $mensaje): bool
    {
        return $mensaje !== null && $mensaje !== ''
            && preg_match(self::DICE_DESTACADO, mb_strtolower($mensaje)) === 1;
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
        if (!empty($data['unidad_nombre'])) {
            $lines[] = "• Se vende por: <b>{$data['unidad_nombre']}</b>";
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
        if (isset($data['stock_minimo'])) {
            $lines[] = (int) $data['stock_minimo'] === 0
                ? "• Avisar cuando queden: <b>no avisar</b>"
                : "• Avisar cuando queden: <b>{$data['stock_minimo']}</b>";
        }
        if (!empty($data['destacado'])) {
            $lines[] = "• ⭐ Destacado en la tienda";
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
