<?php

namespace App\Services\Telegram;

use App\Models\TelegramConversation;
use App\Models\Category;
use App\DTOs\ProductData;
use App\Services\CategoryService;
use App\Services\Messaging\TelegramService;
use App\Services\ProductService;
use App\Support\NumberParser;
use App\Support\TelegramKeyboard;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class BotProductHandler
{
    /** Resumen con botones, previo a guardar (solo altas dictadas por audio). */
    private const STEP_REVISAR = 'nuevo:revisar';

    /** Menú de "qué dato quiero corregir". */
    private const STEP_EDITAR = 'nuevo:editar';

    /** Prefijo de los pasos de corrección: 'nuevo:editar:<campo>'. */
    private const STEP_EDITAR_CAMPO = 'nuevo:editar:';

    /** Prefijo de los botones de este flujo, tal como los rutea BotHandler. */
    private const BOTON = 'prod:';

    /**
     * Datos corregibles desde el resumen, en el orden en que se muestran.
     * La clave es el campo guardado; el valor, el nombre que lee la persona.
     */
    private const CAMPOS_EDITABLES = [
        'nombre'        => 'Nombre',
        'categoria'     => 'Categoría',
        'precio_compra' => 'Precio de compra',
        'precio_venta'  => 'Precio de venta',
        'cantidad'      => 'Cantidad',
        'foto'          => 'Foto',
    ];

    public function __construct(
        protected TelegramService $telegram,
        protected ProductService $productService,
        protected CategoryService $categoryService,
    ) {}

    public function handle(string $chatId, array $message): void
    {
        $conversation = TelegramConversation::getOrCreate($chatId);
        $text = trim($message['text'] ?? '');

        // Check for cancel command
        if (strtolower($text) === '/cancelar' || strtolower($text) === 'cancel') {
            $conversation->delete();
            $this->telegram->sendMessage($chatId, "❌ Operación cancelada.");
            return;
        }

        // Route to appropriate handler
        if (str_starts_with($conversation->step, 'nuevo:')) {
            $this->handleNuevoFlow($chatId, $conversation, $message, $text);
        }
    }

    public function start(string $chatId): void
    {
        $conversation = TelegramConversation::getOrCreate($chatId);
        $conversation->update([
            'step' => 'nuevo:nombre',
            'data' => [],
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "📝 <b>Registro de nuevo producto</b>\n\n" .
            "¿Cuál es el <b>nombre del producto</b>?\n\n" .
            "(Escribe /cancelar para salir)"
        );
    }

    private function handleNuevoFlow(string $chatId, TelegramConversation $conversation, array $message, string $text): void
    {
        $step = $conversation->step;

        // Los pasos de corrección llevan el campo en el propio nombre del paso
        // ('nuevo:editar:precio_venta'), así que no entran en el match de abajo.
        if (str_starts_with($step, self::STEP_EDITAR_CAMPO)) {
            $this->aplicarCorreccion(
                $chatId,
                $conversation,
                substr($step, strlen(self::STEP_EDITAR_CAMPO)),
                $text
            );
            return;
        }

        match ($step) {
            self::STEP_REVISAR => $this->textoEnRevision($chatId, $conversation, $text),
            self::STEP_EDITAR  => $this->textoEnMenuEdicion($chatId, $conversation, $text),
            'nuevo:nombre' => $this->askNombre($chatId, $conversation, $text),
            'nuevo:categoria' => $this->askCategoria($chatId, $conversation, $text),
            'nuevo:precio_compra' => $this->askPrecioCompra($chatId, $conversation, $text),
            'nuevo:precio_venta' => $this->askPrecioVenta($chatId, $conversation, $text),
            'nuevo:cantidad' => $this->askCantidad($chatId, $conversation, $text),
            'nuevo:foto' => $this->askFoto($chatId, $conversation, $message),
            'nuevo:confirmar' => $this->confirm($chatId, $conversation, $text),
            default => $this->telegram->sendMessage($chatId, "❓ Estado desconocido. /cancelar y reintenta."),
        };
    }

    private function askNombre(string $chatId, TelegramConversation $conversation, string $nombre): void
    {
        if (empty($nombre) || strlen($nombre) < 3) {
            $this->telegram->sendMessage($chatId, "❌ El nombre debe tener al menos 3 caracteres.");
            return;
        }

        $data = $conversation->data ?? [];
        $data['nombre'] = $nombre;

        $conversation->update([
            'step' => 'nuevo:categoria',
            'data' => $data,
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->telegram->sendMessage($chatId, "📦 Nombre: <b>{$nombre}</b>\n\n" . $this->buildCategoryPrompt());
    }

    private function buildCategoryPrompt(): string
    {
        $categories = Category::orderBy('name')->limit(12)->get();
        $total = Category::count();

        $msg = "¿Cuál es la <b>categoría</b>?\n\n";

        if ($categories->isEmpty()) {
            $msg .= "No hay categorías aún. Escribe el nombre de la nueva categoría.";
        } else {
            foreach ($categories as $idx => $cat) {
                $msg .= ($idx + 1) . ". {$cat->name}\n";
            }
            if ($total > 12) {
                $msg .= "... ({$total} en total)\n";
            }
            $msg .= "\nEscribe el <b>número</b> o el <b>nombre</b>.\n";
            $msg .= "<i>Si no existe la categoría, escríbela y te pregunto si crear.</i>";
        }

        return $msg;
    }

    private function askCategoria(string $chatId, TelegramConversation $conversation, string $input): void
    {
        $data      = $conversation->data ?? [];
        $inputLow  = mb_strtolower(trim($input));

        // ── Pending create confirmation ──────────────────────────────────────
        if (!empty($data['categoria_pending'])) {
            $pendingName = $data['categoria_pending'];

            if (\in_array($inputLow, ['si', 'sí', 's', 'yes', '1'], true)) {
                $this->createAndAdvanceCategory($chatId, $conversation, $data, $pendingName);
                return;
            }

            if (\in_array($inputLow, ['no', 'n', '2'], true)) {
                unset($data['categoria_pending']);
                $conversation->update(['data' => $data]);
                $this->telegram->sendMessage($chatId, $this->buildCategoryPrompt());
                return;
            }

            // User typed a new name instead → clear pending, fall through to search
            unset($data['categoria_pending']);
            $conversation->update(['data' => $data]);
        }

        // ── Number selection ─────────────────────────────────────────────────
        if (ctype_digit(trim($input))) {
            $idx      = (int) $input - 1;
            $category = Category::orderBy('name')->offset($idx)->limit(1)->first();
            if ($category) {
                $this->setCategoryAndAdvance($chatId, $conversation, $data, $category);
                return;
            }
        }

        // ── Text search (fuzzy) ──────────────────────────────────────────────
        $category = $this->findCategoryByText($input);
        if ($category) {
            $this->setCategoryAndAdvance($chatId, $conversation, $data, $category);
            return;
        }

        // ── Not found → ask to create ────────────────────────────────────────
        $similar = Category::whereRaw('LOWER(name) LIKE ?', ['%' . $inputLow . '%'])
            ->orderBy('name')
            ->limit(3)
            ->get();

        $data['categoria_pending'] = $input;
        $conversation->update(['data' => $data, 'expires_at' => now()->addMinutes(30)]);

        $msg = "❓ No encontré \"<b>{$input}</b>\".\n\n";
        if ($similar->isNotEmpty()) {
            $msg .= "¿Quisiste decir?\n";
            foreach ($similar as $cat) {
                $msg .= "• {$cat->name}\n";
            }
            $msg .= "\n";
        }
        $msg .= "¿Crear la categoría <b>\"{$input}\"</b>?\n<i>Responde sí/no o escribe otro nombre.</i>";

        $this->telegram->sendMessage($chatId, $msg);
    }

    private function findCategoryByText(string $input): ?Category
    {
        // Exact match (case-insensitive)
        $cat = Category::whereRaw('LOWER(name) = LOWER(?)', [$input])->first();
        if ($cat) {
            return $cat;
        }

        // Contains match
        $cat = Category::whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($input) . '%'])->first();
        if ($cat) {
            return $cat;
        }

        // Normalized (accent-stripped) match
        $normalized = mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $input) ?: $input);
        if ($normalized !== mb_strtolower($input)) {
            $all = Category::orderBy('name')->get();
            foreach ($all as $cat) {
                $catNorm = mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $cat->name) ?: $cat->name);
                if (str_contains($catNorm, $normalized)) {
                    return $cat;
                }
            }
        }

        return null;
    }

    private function setCategoryAndAdvance(string $chatId, TelegramConversation $conversation, array $data, Category $category): void
    {
        $data['categoria_id']     = $category->id;
        $data['categoria_nombre'] = $category->name;
        unset($data['categoria_pending']);

        $this->telegram->sendMessage($chatId, "✅ Categoría: <b>{$category->name}</b>");

        $this->advanceToNextMissingStep($chatId, $conversation, $data);
    }

    /**
     * Determina cuál es el siguiente campo faltante y avanza ahí.
     * Usado tras llenar cualquier campo (categoría, precios, cantidad) cuando
     * el flujo viene pre-poblado desde la herramienta de IA (audio multi-campo)
     * y NO debemos volver a preguntar lo que ya tenemos.
     */
    private function advanceToNextMissingStep(string $chatId, TelegramConversation $conversation, array $data): void
    {
        $nextStep = match (true) {
            empty($data['nombre'])         => 'nuevo:nombre',
            empty($data['categoria_id'])   => 'nuevo:categoria',
            empty($data['precio_compra'])  => 'nuevo:precio_compra',
            empty($data['precio_venta'])   => 'nuevo:precio_venta',
            !isset($data['cantidad'])      => 'nuevo:cantidad',
            default                        => 'nuevo:foto',
        };

        $conversation->update([
            'step'       => $nextStep,
            'data'       => $data,
            'expires_at' => now()->addMinutes(30),
        ]);

        // Si ya teníamos TODOS los datos numéricos, saltamos directo a foto.
        $prompt = match ($nextStep) {
            'nuevo:precio_compra' => "¿Cuál es el <b>precio de compra</b>? (número, ej: 25.50)",
            'nuevo:precio_venta'  => "¿Cuál es el <b>precio de venta</b>?",
            'nuevo:cantidad'      => "¿Cuál es la <b>cantidad inicial en stock</b>?",
            'nuevo:foto'          => "Envía una <b>foto del producto</b> (opcional).\nEscribe '<b>omitir</b>' si no tienes foto.",
            default               => '',
        };

        if ($prompt !== '') {
            $this->telegram->sendMessage($chatId, $prompt);
        }
    }

    private function createAndAdvanceCategory(string $chatId, TelegramConversation $conversation, array $data, string $name): void
    {
        try {
            // Mismo camino que el selector del formulario web: reutiliza la
            // categoría si ya existe (ignorando mayúsculas y tildes) y
            // desambigua el slug cuando hace falta.
            $category = $this->categoryService->findOrCreateByName($name);

            $this->telegram->sendMessage(
                $chatId,
                $category->wasRecentlyCreated
                    ? "✅ Categoría creada: <b>{$category->name}</b>"
                    : "ℹ️ Ya tenías la categoría <b>{$category->name}</b>, uso esa."
            );
            $this->setCategoryAndAdvance($chatId, $conversation, $data, $category);
        } catch (\Exception $e) {
            Log::error('Category creation failed in bot', ['error' => $e->getMessage()]);
            $this->telegram->sendMessage($chatId, "❌ Error al crear categoría: " . $e->getMessage() . "\n\nIntenta de nuevo.");
        }
    }

    private function askPrecioCompra(string $chatId, TelegramConversation $conversation, string $input): void
    {
        $price = $this->parsePrice($input);

        if ($price === null) {
            $this->telegram->sendMessage($chatId, "❌ Precio inválido. Ingresa un número (ej: 1500)");
            return;
        }

        $data = $conversation->data ?? [];
        $data['precio_compra'] = $price;

        $formatted = number_format($price / 100, 2);
        $this->telegram->sendMessage($chatId, "💰 Precio de compra: <b>{$formatted}</b>");

        $this->advanceToNextMissingStep($chatId, $conversation, $data);
    }

    private function askPrecioVenta(string $chatId, TelegramConversation $conversation, string $input): void
    {
        $price = $this->parsePrice($input);

        if ($price === null) {
            $this->telegram->sendMessage($chatId, "❌ Precio inválido. Ingresa un número.");
            return;
        }

        $data = $conversation->data ?? [];
        $data['precio_venta'] = $price;

        $formatted = number_format($price / 100, 2);
        $this->telegram->sendMessage($chatId, "💵 Precio de venta: <b>{$formatted}</b>");

        $this->advanceToNextMissingStep($chatId, $conversation, $data);
    }

    private function askCantidad(string $chatId, TelegramConversation $conversation, string $input): void
    {
        $qty = NumberParser::extractInt($input);
        if ($qty === null || $qty < 0) {
            $this->telegram->sendMessage($chatId, "❌ Cantidad inválida. Escribe un número (ej: 10, diez)");
            return;
        }

        $data = $conversation->data ?? [];
        $data['cantidad'] = $qty;

        $this->telegram->sendMessage($chatId, "📊 Cantidad: <b>{$qty}</b>");

        $this->advanceToNextMissingStep($chatId, $conversation, $data);
    }

    private function askFoto(string $chatId, TelegramConversation $conversation, array $message): void
    {
        $data = $conversation->data ?? [];

        // Check if text message (omitir)
        if (isset($message['text'])) {
            if (strtolower(trim($message['text'])) === 'omitir') {
                $data['foto_path'] = null;
                $this->showConfirm($chatId, $conversation, $data);
                return;
            }
            $this->telegram->sendMessage($chatId, "❌ Envía una foto o escribe 'omitir'.");
            return;
        }

        // Handle photo
        if (isset($message['photo'])) {
            try {
                $photo = end($message['photo']); // Get best quality
                $fileId = $photo['file_id'];

                Log::info('Processing photo upload', ['file_id' => $fileId]);

                // Download photo from Telegram
                $filePath = $this->telegram->getFile($fileId);
                $content = $this->telegram->downloadFile($filePath);

                if (empty($content)) {
                    throw new \Exception('Downloaded content is empty');
                }

                // Detect MIME type
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_buffer($finfo, $content);
                finfo_close($finfo);

                $extension = match($mimeType) {
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    default => 'jpg',
                };

                // Ensure directory exists
                Storage::disk('public')->makeDirectory('products');

                // Store in storage
                $storagePath = 'products/' . Str::uuid() . '.' . $extension;
                Storage::disk('public')->put($storagePath, $content);

                Log::info('Photo stored successfully', ['path' => $storagePath, 'mime' => $mimeType]);

                $data['foto_path'] = $storagePath;
                $this->showConfirm($chatId, $conversation, $data);
            } catch (\Exception $e) {
                Log::error('Photo download/storage error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $data['foto_path'] = null;
                $this->telegram->sendMessage($chatId, "⚠️ Error: " . $e->getMessage() . "\n\nContinuando sin imagen...");
                $this->showConfirm($chatId, $conversation, $data);
            }
            return;
        }

        $this->telegram->sendMessage($chatId, "❌ Envía una foto o escribe 'omitir'.");
    }

    private function showConfirm(string $chatId, TelegramConversation $conversation, array $data): void
    {
        // El alta dictada se revisa con botones: la transcripción pudo escribir
        // mal cualquier dato y hay que poder corregirlo sin repetir el audio.
        if (!empty($data['origen_audio'])) {
            $this->showReview($chatId, $conversation, $data);
            return;
        }

        $conversation->update([
            'step' => 'nuevo:confirmar',
            'data' => $data,
            'expires_at' => now()->addMinutes(30),
        ]);

        $precioCompra = number_format($data['precio_compra'] / 100, 2);
        $precioVenta = number_format($data['precio_venta'] / 100, 2);

        $message = "📋 <b>Resumen del producto</b>\n\n";
        $message .= "Nombre: <b>{$data['nombre']}</b>\n";
        $message .= "Categoría: {$data['categoria_nombre']}\n";
        $message .= "Precio compra: {$precioCompra}\n";
        $message .= "Precio venta: {$precioVenta}\n";
        $message .= "Stock inicial: {$data['cantidad']}\n";
        $message .= "Foto: " . (isset($data['foto_path']) && $data['foto_path'] ? "✅ Sí" : "❌ No") . "\n\n";
        $message .= "<b>¿Confirmar crear producto?</b>\n\n";
        $message .= "Responde: 'sí' o 'no'";

        $this->telegram->sendMessage($chatId, $message);
    }

    private function confirm(string $chatId, TelegramConversation $conversation, string $text): void
    {
        if (strtolower($text) !== 'sí' && strtolower($text) !== 'si') {
            $conversation->delete();
            $this->telegram->sendMessage($chatId, "❌ Producto no creado.");
            return;
        }

        $this->crearProducto($chatId, $conversation, $conversation->data ?? []);
    }

    /**
     * Único punto donde el alta se escribe en la base. Lo comparten la
     * confirmación escrita ("sí") y el botón Guardar del resumen.
     */
    private function crearProducto(string $chatId, TelegramConversation $conversation, array $data): void
    {
        try {
            $productData = ProductData::fromArray([
                'category_id' => $data['categoria_id'],
                'unit_id' => 1, // Default unit for MVP - TODO: allow unit selection
                'sku' => null, // Auto-generate
                'name' => $data['nombre'],
                'purchase_price' => $data['precio_compra'],
                'selling_price' => $data['precio_venta'],
                'quantity' => $data['cantidad'],
                'min_stock' => max(1, intval($data['cantidad'] * 0.2)), // 20% of initial qty
                'is_active' => true,
                'is_public' => true, // productos de Telegram salen al catálogo público automáticamente
                'description' => null,
                'notes' => "Creado vía Telegram",
                'image_path' => $data['foto_path'] ?? null,
            ]);

            $product = $this->productService->createProduct($productData);

            $conversation->delete();
            $this->telegram->sendMessage(
                $chatId,
                "✅ <b>Producto creado!</b>\n\n" .
                "SKU: {$product->sku}\n" .
                "Nombre: {$product->name}\n\n" .
                "Ya está disponible en tu inventario."
            );
        } catch (\Exception $e) {
            Log::error('Product creation error', ['error' => $e->getMessage()]);
            $this->telegram->sendMessage($chatId, "❌ Error al crear el producto. Intenta de nuevo o /cancelar.");

            // El alta con botones ya se quedó sin ellos al tocar Guardar; si el
            // guardado falló hay que devolverlos o la persona queda sin salida.
            if (!empty($data['origen_audio'])) {
                $this->showReview($chatId, $conversation, $data);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Revisión con botones (altas dictadas por audio)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Muestra todo lo entendido y espera que la persona decida: Guardar o
     * Editar. Mientras está en este paso NADA se escribió en la base.
     */
    private function showReview(string $chatId, TelegramConversation $conversation, array $data): void
    {
        $conversation->update([
            'step'       => self::STEP_REVISAR,
            'data'       => $data,
            'expires_at' => now()->addMinutes(30),
        ]);

        $message  = "📋 <b>Revisá antes de guardar</b>\n\n";
        $message .= "Nombre: <b>" . ($data['nombre'] ?? '—') . "</b>\n";
        $message .= "Categoría: " . ($data['categoria_nombre'] ?? '—') . "\n";
        $message .= "Precio de compra: " . $this->formatBs($data['precio_compra'] ?? null) . "\n";
        $message .= "Precio de venta: " . $this->formatBs($data['precio_venta'] ?? null) . "\n";
        $message .= "Cantidad: " . ($data['cantidad'] ?? '—') . "\n";
        $message .= "Foto: " . (!empty($data['foto_path']) ? "sí" : "no") . "\n\n";
        $message .= "Si algo está mal, tocá <b>Editar</b>.\n";
        $message .= "Nada se guarda hasta que toques <b>Guardar</b>.";

        $keyboard = TelegramKeyboard::make()->row([
            '✅ Guardar' => self::BOTON . 'guardar',
            '✏️ Editar'  => self::BOTON . 'editar',
        ]);

        $this->telegram->sendMessage($chatId, $message, 'HTML', $keyboard->toArray());
    }

    /** Un botón por dato corregible, más la vuelta al resumen. */
    private function showEditMenu(string $chatId, TelegramConversation $conversation, array $data): void
    {
        $conversation->update([
            'step'       => self::STEP_EDITAR,
            'data'       => $data,
            'expires_at' => now()->addMinutes(30),
        ]);

        $keyboard = TelegramKeyboard::make();
        foreach (self::CAMPOS_EDITABLES as $campo => $etiqueta) {
            $keyboard->button($etiqueta, self::BOTON . 'campo:' . $campo);
        }
        $keyboard->button('◀️ Volver al resumen', self::BOTON . 'volver');

        $this->telegram->sendMessage(
            $chatId,
            "✏️ <b>¿Qué dato querés corregir?</b>\n\nTocá el que está mal.",
            'HTML',
            $keyboard->toArray()
        );
    }

    /** Pide el valor nuevo de un solo campo y deja el paso esperándolo. */
    private function askFieldEdit(string $chatId, TelegramConversation $conversation, array $data, string $campo): void
    {
        if (!isset(self::CAMPOS_EDITABLES[$campo])) {
            $this->showEditMenu($chatId, $conversation, $data);
            return;
        }

        // La foto reutiliza el paso normal de foto: al recibirla, el flujo
        // vuelve solo al resumen por showConfirm().
        $step = $campo === 'foto' ? 'nuevo:foto' : self::STEP_EDITAR_CAMPO . $campo;

        $conversation->update([
            'step'       => $step,
            'data'       => $data,
            'expires_at' => now()->addMinutes(30),
        ]);

        $prompt = match ($campo) {
            'nombre'        => "✏️ Escribí el <b>nombre</b> correcto del producto.",
            'categoria'     => "✏️ ¿Cuál es la <b>categoría</b> correcta?\n\n" . $this->buildCategoryPrompt(),
            'precio_compra' => "✏️ Escribí el <b>precio de compra</b> correcto (ej: 350).",
            'precio_venta'  => "✏️ Escribí el <b>precio de venta</b> correcto (ej: 450).",
            'cantidad'      => "✏️ Escribí la <b>cantidad</b> correcta (ej: 30).",
            'foto'          => "📸 Enviá la <b>foto</b> del producto.\nEscribí <b>omitir</b> si no querés foto.",
        };

        $this->telegram->sendMessage($chatId, $prompt);
    }

    /** Guarda el valor corregido y vuelve al resumen. */
    private function aplicarCorreccion(string $chatId, TelegramConversation $conversation, string $campo, string $text): void
    {
        $data = $conversation->data ?? [];
        $text = trim($text);

        switch ($campo) {
            case 'nombre':
                if (mb_strlen($text) < 3) {
                    $this->telegram->sendMessage($chatId, "❌ El nombre debe tener al menos 3 letras. Escribilo otra vez.");
                    return;
                }
                $data['nombre'] = $text;
                break;

            case 'precio_compra':
            case 'precio_venta':
                $price = $this->parsePrice($text);
                if ($price === null) {
                    $this->telegram->sendMessage($chatId, "❌ No entendí ese precio. Escribí un número (ej: 350).");
                    return;
                }
                $data[$campo] = $price;
                break;

            case 'cantidad':
                $qty = NumberParser::extractInt($text);
                if ($qty === null || $qty < 0) {
                    $this->telegram->sendMessage($chatId, "❌ No entendí esa cantidad. Escribí un número (ej: 30).");
                    return;
                }
                $data['cantidad'] = $qty;
                break;

            case 'categoria':
                // Puede abrir un ida y vuelta (crear la categoría o no), así que
                // decide ella misma si ya corresponde volver al resumen.
                $this->corregirCategoria($chatId, $conversation, $data, $text);
                return;

            default:
                $this->showReview($chatId, $conversation, $data);
                return;
        }

        $this->showReview($chatId, $conversation, $data);
    }

    /**
     * Corrección de categoría: número de la lista, nombre existente, o nombre
     * nuevo (en ese caso se pregunta antes de crearla).
     */
    private function corregirCategoria(string $chatId, TelegramConversation $conversation, array $data, string $input): void
    {
        $inputLow = mb_strtolower(trim($input));

        if (!empty($data['editar_categoria_pendiente'])) {
            $pendiente = $data['editar_categoria_pendiente'];

            if (\in_array($inputLow, ['si', 'sí', 's', 'yes', '1'], true)) {
                unset($data['editar_categoria_pendiente']);
                try {
                    $category = $this->categoryService->findOrCreateByName($pendiente);
                } catch (\Exception $e) {
                    Log::error('Category creation failed while editing bot product', ['error' => $e->getMessage()]);
                    $this->telegram->sendMessage($chatId, "❌ No pude crear esa categoría. Probá con otro nombre.");
                    return;
                }
                $this->telegram->sendMessage($chatId, "✅ Categoría creada: <b>{$category->name}</b>");
                $this->setCategoriaYRevisar($chatId, $conversation, $data, $category);
                return;
            }

            if (\in_array($inputLow, ['no', 'n', '2'], true)) {
                unset($data['editar_categoria_pendiente']);
                $conversation->update(['data' => $data, 'expires_at' => now()->addMinutes(30)]);
                $this->telegram->sendMessage($chatId, "Entonces, ¿cuál es la categoría?\n\n" . $this->buildCategoryPrompt());
                return;
            }

            // Escribió otro nombre en lugar de responder: seguimos buscando ese.
            unset($data['editar_categoria_pendiente']);
        }

        if (ctype_digit(trim($input))) {
            $category = Category::orderBy('name')->offset((int) $input - 1)->limit(1)->first();
            if ($category) {
                $this->setCategoriaYRevisar($chatId, $conversation, $data, $category);
                return;
            }
        }

        $category = $this->findCategoryByText($input);
        if ($category) {
            $this->setCategoriaYRevisar($chatId, $conversation, $data, $category);
            return;
        }

        $data['editar_categoria_pendiente'] = trim($input);
        $conversation->update(['data' => $data, 'expires_at' => now()->addMinutes(30)]);

        $this->telegram->sendMessage(
            $chatId,
            "❓ No encontré la categoría \"<b>{$input}</b>\".\n\n" .
            "¿La creo? Respondé <b>sí</b> o <b>no</b>, o escribí otro nombre."
        );
    }

    private function setCategoriaYRevisar(string $chatId, TelegramConversation $conversation, array $data, Category $category): void
    {
        $data['categoria_id']     = $category->id;
        $data['categoria_nombre'] = $category->name;
        unset($data['categoria_pending'], $data['editar_categoria_pendiente']);

        $this->showReview($chatId, $conversation, $data);
    }

    /**
     * Toque de un botón de este flujo. BotHandler ya validó quién lo tocó y
     * que tenga acceso al bot; acá solo importa que el registro siga vivo.
     */
    public function handleButton(string $chatId, string $action, ?int $messageId = null): void
    {
        $conversation = TelegramConversation::where('chat_id', $chatId)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        // Alta ya guardada, cancelada o vencida: el botón viejo sigue visible en
        // el chat, pero no puede revivir ni duplicar nada.
        if (!$conversation || !$this->enRevision($conversation->step)) {
            $this->telegram->sendMessage(
                $chatId,
                "Este registro ya no está disponible. Si querés cargar un producto, usá /nuevo."
            );
            return;
        }

        $data = $conversation->data ?? [];

        if ($action === 'guardar') {
            $faltantes = $this->datosFaltantes($data);
            if ($faltantes !== []) {
                $this->telegram->sendMessage(
                    $chatId,
                    "Todavía falta: <b>" . implode('</b>, <b>', $faltantes) . "</b>. Tocá Editar para completarlo."
                );
                return;
            }

            // Apagamos los botones del resumen antes de guardar: así un segundo
            // toque sobre el mismo mensaje ya no ofrece la acción.
            if ($messageId !== null) {
                $this->telegram->editMessageReplyMarkup($chatId, $messageId, null);
            }

            $this->crearProducto($chatId, $conversation, $data);
            return;
        }

        if ($action === 'editar') {
            $this->showEditMenu($chatId, $conversation, $data);
            return;
        }

        if ($action === 'volver') {
            $this->showReview($chatId, $conversation, $data);
            return;
        }

        if (str_starts_with($action, 'campo:')) {
            $this->askFieldEdit($chatId, $conversation, $data, substr($action, strlen('campo:')));
            return;
        }

        Log::warning('Botón de alta de producto no reconocido', ['action' => $action]);
        $this->showReview($chatId, $conversation, $data);
    }

    /** Pasos en los que los botones del resumen tienen sentido. */
    private function enRevision(string $step): bool
    {
        return $step === self::STEP_REVISAR
            || $step === self::STEP_EDITAR
            || str_starts_with($step, self::STEP_EDITAR_CAMPO)
            || $step === 'nuevo:foto'; // corrección de foto en curso
    }

    /** @return array<int, string> nombres legibles de lo que falta para guardar */
    private function datosFaltantes(array $data): array
    {
        $faltan = [];
        if (empty($data['nombre']))        { $faltan[] = 'el nombre'; }
        if (empty($data['categoria_id']))  { $faltan[] = 'la categoría'; }
        if (empty($data['precio_compra'])) { $faltan[] = 'el precio de compra'; }
        if (empty($data['precio_venta']))  { $faltan[] = 'el precio de venta'; }
        if (!isset($data['cantidad']))     { $faltan[] = 'la cantidad'; }
        return $faltan;
    }

    /** Si escribe en vez de tocar: aceptamos las dos palabras y, si no, guiamos. */
    private function textoEnRevision(string $chatId, TelegramConversation $conversation, string $text): void
    {
        $lower = mb_strtolower(trim($text));

        if (\in_array($lower, ['guardar', 'guarda', 'sí', 'si', 'listo'], true)) {
            $this->handleButton($chatId, 'guardar');
            return;
        }

        if (\in_array($lower, ['editar', 'edita', 'corregir'], true)) {
            $this->showEditMenu($chatId, $conversation, $conversation->data ?? []);
            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            "Tocá <b>Guardar</b> o <b>Editar</b> en el mensaje de arriba.\n\n" .
            "(O escribí /cancelar para dejarlo)"
        );
    }

    private function textoEnMenuEdicion(string $chatId, TelegramConversation $conversation, string $text): void
    {
        $lower = mb_strtolower(trim($text));
        $data  = $conversation->data ?? [];

        // Escribir el nombre del dato también sirve: es lo natural si viene de
        // dictar y no de tocar botones.
        foreach (self::CAMPOS_EDITABLES as $campo => $etiqueta) {
            if ($lower === mb_strtolower($etiqueta) || $lower === $campo) {
                $this->askFieldEdit($chatId, $conversation, $data, $campo);
                return;
            }
        }

        $this->telegram->sendMessage($chatId, "Tocá el dato que querés corregir en el mensaje de arriba.");
    }

    private function formatBs(?int $centavos): string
    {
        if ($centavos === null) {
            return '—';
        }

        return number_format($centavos / 100, 2) . ' Bs';
    }

    private function parsePrice(string $input): ?int
    {
        $value = NumberParser::extractFloat($input);
        if ($value === null || $value <= 0) {
            return null;
        }
        return (int) round($value * 100);
    }
}
