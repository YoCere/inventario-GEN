<?php

namespace App\Services\Telegram;

use App\Models\BotKnowledge;
use App\Models\Setting;
use App\Models\TelegramConversation;
use App\Models\Product;
use App\Services\Agent\VisionService;
use App\Services\Agent\WhisperService;
use App\Services\Messaging\TelegramService;
use App\Services\Messaging\ProductSearchService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BotHandler
{
    public function __construct(
        protected TelegramService $telegram,
        protected ProductSearchService $searchService,
        protected BotProductHandler $productHandler,
        protected BotSaleHandler $saleHandler,
        protected BotRefundHandler $refundHandler,
        protected BotAuthHandler $authHandler,
        protected BotReportHandler $reportHandler,
        protected BotAgentHandler $agentHandler,
        protected WhisperService $whisperService,
        protected VisionService $visionService,
        protected ReminderHandler $reminderHandler,
    ) {}

    public function dispatch(array $update): void
    {
        try {
            $message = $update['message'] ?? null;

            if (!$message) {
                return;
            }

            // Channel posts, edited messages y service updates pueden NO traer 'from'.
            $fromId = $message['from']['id'] ?? null;
            if ($fromId === null) {
                return;
            }
            $chatId = (string) $fromId;

            // CHECK AUTHENTICATION FIRST
            // Exclude expired conversations so stale flows from yesterday don't reactivate.
            $conversation = TelegramConversation::where('chat_id', $chatId)
                ->where('step', '!=', 'idle')
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->first();

            // Handle auth conversations
            if ($conversation && str_starts_with($conversation->step, 'auth:')) {
                $this->authHandler->handle($chatId, $message);
                return;
            }

            // Check if user is authenticated
            if (!$this->authHandler->isAuthenticated($chatId)) {
                // Not authenticated - start login
                $this->authHandler->startLogin($chatId);
                return;
            }

            // USER IS AUTHENTICATED - continue with bot logic

            // Check if bot is paused (admin can still use all commands)
            $adminChatId = Setting::get('telegram_admin_chat_id', '');
            if (Setting::get('telegram_bot_paused', '0') === '1' && $chatId !== $adminChatId) {
                $this->telegram->sendMessage($chatId, "🔴 Bot temporalmente detenido.");
                return;
            }

            // Voice/audio messages
            if (isset($message['voice']) || isset($message['audio'])) {
                $this->handleVoiceMessage($chatId, $message);
                return;
            }

            // Photo messages — debe manejarse ANTES del early-return por texto vacío.
            // Telegram envía las fotos con 'caption' (no 'text'); sin esta rama se caen
            // silenciosamente y el usuario queda sin respuesta.
            if (isset($message['photo'])) {
                // Flujo activo de alta de producto: la foto va al paso 'nuevo:foto'.
                if ($conversation && str_starts_with($conversation->step, 'nuevo:')) {
                    $this->productHandler->handle($chatId, $message);
                    return;
                }

                $caption = trim($message['caption'] ?? '');

                // Vision habilitado + key del provider activo → describir + buscar.
                if ($this->visionService->isEnabled()) {
                    $this->handlePhotoSearch($chatId, $message, $caption);
                    return;
                }

                // Vision off pero el usuario mandó caption → usar caption como query.
                // Soluciona "foto + 'tenemos este Redmi 14c?'" cuando Vision está apagado.
                if ($caption !== '') {
                    $this->telegram->sendMessage(
                        $chatId,
                        "📷 <i>Búsqueda por imagen no activa. Usando el texto que enviaste para buscar…</i>"
                    );
                    $message['text'] = $caption;
                    // Continúa al routing de texto abajo con caption como query.
                } else {
                    // Vision off + sin caption → explicar en lugar de quedarse mudo.
                    $this->telegram->sendMessage(
                        $chatId,
                        "📷 <b>Búsqueda por imagen no disponible</b>\n\n" .
                        $this->visionService->unavailableReason() . "\n\n" .
                        "Mientras tanto, escribe el nombre del producto."
                    );
                    return;
                }
            }

            if (empty($message['text'])) {
                return;
            }

            $text = trim($message['text']);

            // Commands escape search/report/agent states — only active sale flows intercept them.
            // Exception: /cancelar ALWAYS escapes any flow so users can never get trapped.
            if (str_starts_with($text, '/')) {
                $isActiveFlow = $conversation && (
                    str_starts_with($conversation->step, 'nuevo:') ||
                    str_starts_with($conversation->step, 'venta_rapida:') ||
                    str_starts_with($conversation->step, 'devolver:') ||
                    str_starts_with($conversation->step, 'recordar:') ||
                    $conversation->step === 'recordatorios:gestionar'
                );
                if ($text === '/cancelar' && $isActiveFlow) {
                    $conversation->delete();
                    $this->telegram->sendMessage($chatId, "✅ Proceso cancelado. Escribe un producto para buscar o /ayuda para ver opciones.");
                    return;
                }
                if (!$isActiveFlow) {
                    $conversation?->delete();
                    $this->handleCommand($chatId, $text);
                    return;
                }
            }

            if ($conversation) {
                // If user sends a clearly unrelated sentence while in a structured multi-step flow,
                // show a gentle reminder instead of feeding it to the step handler.
                if ($this->handleOutOfContextInput($chatId, $conversation, $text)) {
                    return;
                }

                // Route to appropriate handler based on conversation type
                if (str_starts_with($conversation->step, 'nuevo:')) {
                    $this->productHandler->handle($chatId, $message);
                    return;
                } elseif (str_starts_with($conversation->step, 'venta_rapida:')) {
                    $this->saleHandler->handle($chatId, $message);
                    return;
                } elseif (str_starts_with($conversation->step, 'devolver:')) {
                    // Handle refund flow
                    $text = trim($message['text'] ?? '');
                    if ($conversation->step === 'devolver:seleccionar') {
                        $this->refundHandler->handle($chatId, $message);
                    } elseif ($conversation->step === 'devolver:confirmar') {
                        if ($text === '1') {
                            $this->refundHandler->confirmRefund($chatId, $conversation);
                        } else {
                            $conversation->delete();
                            $this->telegram->sendMessage($chatId, "❌ Devolución cancelada.");
                        }
                    }
                    return;
                } elseif ($conversation->step === 'busqueda:resultado') {
                    // Handle options after single search result
                    $text = trim($message['text'] ?? '');
                    if ($text === '1' || strtolower($text) === 'vender') {
                        Log::info('User selected option 1: vender', ['chatId' => $chatId]);
                        // Si el resultado vino de búsqueda visual, el usuario eligió vender
                        // = confirmación explícita → elevar source a 'user_confirmed' en memoria.
                        $visionKey = $conversation->data['vision_key'] ?? null;
                        $productId = $conversation->data['product_id'] ?? null;
                        if ($visionKey && $productId) {
                            BotKnowledge::rememberVision($visionKey, (int) $productId, confirmed: true);
                        }
                        $this->handleQuickSaleFromSearch($chatId);
                        return;
                    } elseif ($text === '2') {
                        Log::info('User selected option 2: buscar otro', ['chatId' => $chatId]);
                        $conversation->delete();
                    } else {
                        // Invalid option, treat as new search
                        $conversation->delete();
                    }
                } elseif ($conversation->step === 'busqueda:multiple') {
                    // Handle number selection from multiple results
                    $text = trim($message['text'] ?? '');
                    $this->handleMultipleResultSelection($chatId, $conversation, $text);
                    return;
                } elseif ($conversation->step === 'reportes:menu') {
                    $this->reportHandler->handle($chatId, $message);
                    return;
                } elseif ($conversation->step === 'agent:active') {
                    // Ongoing agent conversation — route directly, skip product search
                    $this->agentHandler->handle($chatId, $text);
                    return;
                } elseif (str_starts_with($conversation->step, 'recordar:') || $conversation->step === 'recordatorios:gestionar') {
                    $this->reminderHandler->handle($chatId, $message);
                    return;
                }
            }

            // Free text routing
            if (strtolower($text) === 'vender') {
                Log::info('User requested quick sale', ['chatId' => $chatId]);
                $this->handleQuickSaleFromSearch($chatId);
            } elseif ($this->isConversationalQuery($text)) {
                // Looks like a question/sentence → skip product search, go straight to AI
                if (Setting::get('ai_chatbot_enabled') === '1') {
                    Log::info('Conversational query → agent', ['chatId' => $chatId]);
                    $this->agentHandler->handle($chatId, $text);
                } else {
                    $this->telegram->sendMessage($chatId, "❓ Escribe el nombre de un producto para buscarlo.");
                }
            } else {
                // Short query → product search first, AI fallback if no match
                $searchResults = $this->searchService->search($text);
                if (!empty($searchResults)) {
                    Log::info('Product search hit', ['chatId' => $chatId]);
                    $this->handleSearch($chatId, $text, $searchResults);
                } elseif (Setting::get('ai_chatbot_enabled') === '1') {
                    Log::info('No product match → agent', ['chatId' => $chatId]);
                    $this->agentHandler->handle($chatId, $text);
                } else {
                    $this->telegram->sendMessage($chatId, "❌ No se encontró ningún producto con \"<i>{$text}</i>\"");
                }
            }
        } catch (\Exception $e) {
            Log::error('Bot dispatch error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            // Never leave the user with no response — send a generic fallback
            try {
                if (isset($chatId)) {
                    $this->telegram->sendMessage($chatId, "⚠️ Ocurrió un error inesperado. Intenta de nuevo o escribe /ayuda.");
                }
            } catch (\Throwable) {
                // If even the fallback fails (Telegram down), log and give up silently
            }
        }
    }

    protected function handleSearch(string $chatId, string $text, ?array $results = null): void
    {
        $results ??= $this->searchService->search($text);

        if (empty($results)) {
            $this->telegram->sendMessage($chatId, "❌ No se encontró ningún producto con \"<i>{$text}</i>\"");
            return;
        }

        if (count($results) === 1) {
            // Single result - store product ID for quick sale
            $productId = $results[0]['id'] ?? null;
            Log::info('Single result found', ['productId' => $productId, 'chatId' => $chatId]);

            if ($productId) {
                $conversation = TelegramConversation::getOrCreate($chatId);
                $updated = $conversation->update([
                    'step' => 'busqueda:resultado',
                    'data' => ['product_id' => $productId],
                    'expires_at' => now()->addMinutes(5),
                ]);
                Log::info('Conversation updated', [
                    'updated' => $updated,
                    'step' => $conversation->step,
                    'data' => $conversation->data,
                ]);
            }

            $message = $results[0]['message'] . "\n\n";
            $message .= "<b>Opciones:</b>\n";
            $message .= "1️⃣ Vender\n";
            $message .= "2️⃣ Buscar otro producto";
            $this->sendProductCard($chatId, $results[0], $message);
        } else {
            // Multiple results - store in conversation for selection by number
            $conversation = TelegramConversation::getOrCreate($chatId);
            $conversation->update([
                'step' => 'busqueda:multiple',
                'data' => ['results' => $results],
                'expires_at' => now()->addMinutes(5),
            ]);

            $message = "📦 <b>Resultados de búsqueda para: {$text}</b>\n\n";
            foreach ($results as $idx => $result) {
                $message .= ($idx + 1) . ". <b>{$result['name']}</b> - {$result['price']}\n";
            }
            $message .= "\n<i>Escribe el número para vender rápido (Ej: 1, 2, 3...)</i>";
            $this->telegram->sendMessage($chatId, $message);
        }
    }

    protected function handleMultipleResultSelection(string $chatId, TelegramConversation $conversation, string $input): void
    {
        $results = $conversation->data['results'] ?? [];
        $trimmed = trim($input);

        // Si input no es entero puro, tratarlo como nueva búsqueda
        if (!ctype_digit($trimmed)) {
            Log::info('Non-numeric input in multi-results, treating as new search', [
                'chatId' => $chatId,
                'input' => $trimmed,
            ]);
            $conversation->delete();
            $this->handleSearch($chatId, $trimmed);
            return;
        }

        $index = (int) $trimmed - 1;

        Log::info('User selected result', ['chatId' => $chatId, 'index' => $index, 'total' => count($results)]);

        if ($index < 0 || $index >= count($results)) {
            $this->telegram->sendMessage($chatId, "❌ Número inválido. Escribe un número entre 1 y " . count($results) . " o escribe otro nombre para buscar.");
            return;
        }

        $selectedResult = $results[$index];
        $productId = $selectedResult['id'] ?? null;

        if (!$productId) {
            $this->telegram->sendMessage($chatId, "❌ Producto no encontrado.");
            $conversation->delete();
            return;
        }

        // Copiar vision_key si la búsqueda vino de visión (para confirmed-save al vender).
        $visionKey = $conversation->data['vision_key'] ?? null;

        $conversation->update([
            'step'       => 'busqueda:resultado',
            'data'       => array_filter(['product_id' => $productId, 'vision_key' => $visionKey]),
            'expires_at' => now()->addMinutes(5),
        ]);

        $message = $selectedResult['message'] . "\n\n";
        $message .= "<b>Opciones:</b>\n";
        $message .= "1️⃣ Vender\n";
        $message .= "2️⃣ Buscar otro producto";
        $this->sendProductCard($chatId, $selectedResult, $message);
    }

    /**
     * Send product card with image if available, fallback to text-only message.
     * Telegram photo caption limit: 1024 chars. If exceeds, splits photo + follow-up text.
     */
    protected function sendProductCard(string $chatId, array $product, string $message): void
    {
        $imagePath = $product['image_path'] ?? null;

        // No image → plain text
        if (!$imagePath || !Storage::disk('public')->exists($imagePath)) {
            $this->telegram->sendMessage($chatId, $message);
            return;
        }

        try {
            // Telegram photo caption max 1024 chars
            if (mb_strlen($message) <= 1024) {
                $this->telegram->sendPhoto($chatId, $imagePath, $message);
            } else {
                // Photo with truncated caption + full text follow-up
                $this->telegram->sendPhoto($chatId, $imagePath, $product['name'] ?? '');
                $this->telegram->sendMessage($chatId, $message);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send product photo, fallback to text', [
                'chat_id' => $chatId,
                'image_path' => $imagePath,
                'error' => $e->getMessage(),
            ]);
            $this->telegram->sendMessage($chatId, $message);
        }
    }

    protected function handleQuickSaleFromSearch(string $chatId): void
    {
        Log::info('handleQuickSaleFromSearch called', ['chatId' => $chatId]);

        $conversation = TelegramConversation::where('chat_id', $chatId)
            ->where('step', 'busqueda:resultado')
            ->first();

        Log::info('Looking for conversation', [
            'chatId' => $chatId,
            'found' => !!$conversation,
            'step' => $conversation?->step,
            'data' => $conversation?->data,
        ]);

        if (!$conversation || empty($conversation->data['product_id'] ?? null)) {
            Log::warning('No conversation or product_id found', ['chatId' => $chatId]);
            $this->telegram->sendMessage($chatId, "❌ Primero busca un producto con su nombre o SKU.");
            return;
        }

        $product = Product::find($conversation->data['product_id']);

        Log::info('Product lookup', [
            'product_id' => $conversation->data['product_id'],
            'found' => !!$product,
        ]);

        if (!$product) {
            $this->telegram->sendMessage($chatId, "❌ Producto no encontrado.");
            $conversation->delete();
            return;
        }

        Log::info('Starting quick sale', ['product_id' => $product->id, 'product_name' => $product->name]);
        $this->saleHandler->startQuickSale($chatId, $product);
    }

    protected function handleCommand(string $chatId, string $text): void
    {
        $parts = explode(' ', $text, 2);
        $command = strtolower($parts[0]);
        $args = $parts[1] ?? '';

        match ($command) {
            '/ayuda', '/help', '/start' => $this->cmdHelp($chatId),
            '/stock' => $this->cmdStock($chatId),
            '/ventas' => $this->cmdSales($chatId),
            '/nuevo' => $this->cmdNewProduct($chatId),
            '/listar' => $this->cmdList($chatId, $args),
            '/devolver' => $this->cmdRefund($chatId),
            '/recordar' => $this->reminderHandler->start($chatId),
            '/recordatorios' => $this->reminderHandler->listAndManage($chatId),
            '/reportes' => $this->cmdReports($chatId),
            '/detener' => $this->cmdDetener($chatId),
            '/activar' => $this->cmdActivar($chatId),
            '/logout', '/salir' => $this->cmdLogout($chatId),
            '/cambiar', '/cambiarusuario' => $this->cmdSwitchUser($chatId),
            '/cancelar' => $this->telegram->sendMessage($chatId, "✅ No hay ningún proceso activo para cancelar."),
            default => $this->telegram->sendMessage($chatId, "❓ Comando no reconocido. Escribe /ayuda para ver opciones."),
        };
    }

    protected function cmdHelp(string $chatId): void
    {
        $user = $this->authHandler->getAuthenticatedUser($chatId);
        $isAdmin = $user && $user->isAdmin();

        $message = "<b>📚 Ayuda - Comandos disponibles</b>\n\n" .
            "/stock — Ver productos en stock crítico\n" .
            "/ventas — Resumen de ventas de hoy\n" .
            "/nuevo — Registrar un nuevo producto\n" .
            "/listar — Listar productos (todas categorías o filtrar)\n" .
            "/devolver — Procesar devoluciones\n" .
            "/recordar — Crear un recordatorio personal\n" .
            "/recordatorios — Ver y cancelar tus recordatorios\n";

        // Reportes solo para admin (gerencia financiera, no operación diaria)
        if ($isAdmin) {
            $message .= "/reportes — Reportes del negocio (libro diario, top ventas, ganancia, etc.) <i>admin</i>\n";
        }

        $message .= "\n<b>🔐 Sesión</b>\n" .
            "/logout — Cerrar sesión completa\n" .
            "/cambiar — Cambiar de usuario\n\n" .
            "<b>💡 Búsqueda directa</b>\n" .
            "Escribe el nombre de un producto y te mostraré el precio y stock.\n\n" .
            "Ej: <code>Redmi 14c</code>\n\n" .
            "<b>Filtros en /listar</b>\n" .
            "/listar — Todas\n" .
            "/listar categoría — Por categoría\n" .
            "/listar bajo — Stock bajo\n" .
            "/listar activos — Solo activos";

        $this->telegram->sendMessage($chatId, $message);
    }

    protected function cmdStock(string $chatId): void
    {
        $products = \App\Models\Product::where('is_active', true)
            ->whereRaw('quantity <= min_stock')
            ->orderBy('quantity')
            ->get();

        if ($products->isEmpty()) {
            $this->telegram->sendMessage($chatId, "✅ Todos los productos tienen stock suficiente");
            return;
        }

        $message = "⚠️ <b>Stock crítico</b> ({$products->count()} productos)\n\n";
        foreach ($products->take(10) as $product) {
            $unit = $product->unit?->symbol ?? 'uni';
            $message .= "• <b>{$product->name}</b>\n   {$product->quantity} {$unit} (mín: {$product->min_stock})\n";
        }

        if ($products->count() > 10) {
            $message .= "\n... y " . ($products->count() - 10) . " más";
        }

        $this->telegram->sendMessage($chatId, $message);
    }

    protected function cmdSales(string $chatId): void
    {
        $today = today();
        $sales = \App\Models\Sale::whereDate('created_at', $today)
            ->where('status', 'completed')
            ->get();

        $message = "💰 <b>Ventas del día (" . $today->format('d/m/Y') . ")</b>\n\n";
        $message .= "Transacciones: {$sales->count()}\n";
        $message .= "Total: " . number_format($sales->sum('total') / 100, 2);

        $this->telegram->sendMessage($chatId, $message);
    }

    protected function cmdNewProduct(string $chatId): void
    {
        $this->productHandler->start($chatId);
    }

    protected function cmdList(string $chatId, string $filter): void
    {
        $filter = strtolower(trim($filter));

        $query = \App\Models\Product::where('is_active', true);

        // Aplicar filtro
        if ($filter === 'bajo') {
            $query->whereRaw('quantity <= min_stock');
            $title = "⚠️ <b>Stock bajo</b>";
        } elseif ($filter === 'activos') {
            $title = "✅ <b>Productos activos</b>";
        } elseif (!empty($filter)) {
            // Filtro por categoría
            $query->whereHas('category', function ($q) use ($filter) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$filter}%"]);
            });
            $title = "📦 <b>Categoría: {$filter}</b>";
        } else {
            $title = "📦 <b>Todos los productos</b>";
        }

        $products = $query->orderBy('name')->limit(15)->get();

        if ($products->isEmpty()) {
            $this->telegram->sendMessage($chatId, "❌ No hay productos con ese filtro");
            return;
        }

        $message = "{$title}\n\n";
        foreach ($products as $product) {
            $unit = $product->unit?->symbol ?? 'uni';
            $price = number_format($product->selling_price / 100, 2);
            $badge = $product->quantity <= $product->min_stock ? '⚠️ ' : '✓ ';
            $message .= "{$badge}<b>{$product->name}</b>\n" .
                "   Precio: {$price} | Stock: {$product->quantity} {$unit}\n";
        }

        if ($products->count() >= 15) {
            $message .= "\n... (mostrando 15 primeros)";
        }

        $this->telegram->sendMessage($chatId, $message);
    }

    protected function cmdRefund(string $chatId): void
    {
        $this->refundHandler->start($chatId);
    }

    /**
     * /reportes — gate admin-only. Staff no ve cifras financieras del negocio.
     */
    protected function cmdReports(string $chatId): void
    {
        $user = $this->authHandler->getAuthenticatedUser($chatId);

        if (! $user || ! $user->isAdmin()) {
            $this->telegram->sendMessage(
                $chatId,
                "⛔ <b>Acceso restringido</b>\n\n" .
                "Los reportes financieros del negocio están disponibles solo para administradores. " .
                "Tu rol actual no tiene permiso."
            );
            return;
        }

        $this->reportHandler->showMenu($chatId);
    }

    /**
     * /logout — cerrar sesión completa. Olvida el identifier — próximo login
     * pide usuario + contraseña desde cero.
     */
    protected function cmdLogout(string $chatId): void
    {
        $user = $this->authHandler->getAuthenticatedUser($chatId);
        $name = $user?->name ?? 'Usuario';

        $this->authHandler->logout($chatId, forgetIdentifier: true);

        $this->telegram->sendMessage(
            $chatId,
            "👋 <b>Sesión cerrada</b>\n\nHasta luego, {$name}.\n\nEscribe cualquier mensaje para volver a iniciar sesión."
        );
    }

    /**
     * /cambiar — alias semántico de logout. UX: usuario sabe que va a cambiar
     * de cuenta, no que está cerrando sesión definitivamente.
     */
    protected function cmdSwitchUser(string $chatId): void
    {
        $this->authHandler->logout($chatId, forgetIdentifier: true);

        $this->telegram->sendMessage(
            $chatId,
            "🔄 <b>Cambio de usuario</b>\n\nSesión anterior cerrada.\nIngresa tus credenciales del nuevo usuario."
        );

        // Inmediatamente dispara flujo de login para que el siguiente mensaje
        // del usuario sea ya el identifier (no tiene que mandar 'hola' primero).
        $this->authHandler->startLogin($chatId);
    }

    protected function cmdDetener(string $chatId): void
    {
        $adminChatId = Setting::get('telegram_admin_chat_id', '');
        if ($chatId !== $adminChatId) {
            $this->telegram->sendMessage($chatId, "⛔ Solo el administrador puede usar este comando.");
            return;
        }
        Setting::set('telegram_bot_paused', '1');
        $this->telegram->sendMessage($chatId, "🔴 Bot detenido. Los usuarios verán mensaje de pausa.\n\nEscribe /activar para reanudar.");
    }

    protected function cmdActivar(string $chatId): void
    {
        $adminChatId = Setting::get('telegram_admin_chat_id', '');
        if ($chatId !== $adminChatId) {
            $this->telegram->sendMessage($chatId, "⛔ Solo el administrador puede usar este comando.");
            return;
        }
        Setting::set('telegram_bot_paused', '0');
        $this->telegram->sendMessage($chatId, "✅ Bot activado y listo.");
    }

    /**
     * Recibe foto del cliente sin flujo activo. Pasa imagen a VisionService
     * → descripción en texto → ProductSearchService busca → muestra match.
     *
     * Si el usuario adjuntó caption (ej. "tenemos este Redmi 14C?"), se pasa
     * como hint al modelo de visión y también se usa como query alterna al
     * buscar (la unión de descripción + caption suele rescatar matches que
     * solo la descripción no logra).
     */
    protected function handlePhotoSearch(string $chatId, array $message, string $caption = ''): void
    {
        try {
            $this->telegram->sendChatAction($chatId, 'typing');
            $this->telegram->sendMessage($chatId, "🔍 <i>Analizando la imagen…</i>");

            // Telegram envía array de versiones; la última es la mayor resolución.
            $photo = end($message['photo']);
            $fileId = $photo['file_id'] ?? null;
            if (! $fileId) {
                $this->telegram->sendMessage($chatId, "❌ No pude leer la imagen.");
                return;
            }

            $filePath = $this->telegram->getFile($fileId);
            $imageBinary = $this->telegram->downloadFile($filePath);

            $description = $this->visionService->describeProductImage(
                $imageBinary,
                mimeType: null,
                hint: $caption !== '' ? $caption : null,
            );

            if (empty($description)) {
                // Vision no identificó nada — si hay caption y NO es pregunta/frase,
                // intentar búsqueda con el texto del caption (ej. "Redmi 14c").
                // Si el caption ES pregunta/frase ("Tenemos este producto?"), usarlo como
                // query produciría resultados basura — mejor pedir el nombre directo.
                if ($caption !== '' && ! $this->isConversationalQuery($caption)) {
                    $this->telegram->sendMessage(
                        $chatId,
                        "👁️ No pude identificar el producto en la foto. Probando con el texto que enviaste: <i>" .
                        htmlspecialchars($caption, ENT_QUOTES) . "</i>"
                    );
                    $results = $this->searchService->search($caption);
                    if (! empty($results)) {
                        $this->handleSearch($chatId, $caption, $results);
                        return;
                    }
                }

                $this->telegram->sendMessage(
                    $chatId,
                    "❌ No pude identificar el producto en la imagen.\n\n" .
                    "✏️ Escribe el <b>nombre del producto</b> para buscarlo directamente."
                );
                return;
            }

            // Query combinada: descripción de visión + caption (si aporta marca/modelo).
            // Caption conversacional ("tenemos este?") ya se filtró arriba, por lo que
            // si llega aquí es un nombre de producto real y vale la pena combinarlo.
            $fullQuery = $caption !== ''
                ? trim($description . ' ' . $caption)
                : $description;

            // Búsqueda enfocada: los modelos de visión describen en formato
            // "[tipo] [marca] [color] [características]". Las palabras clave más
            // distintivas están primero; lo que viene después (colores, adjetivos,
            // preposiciones) introduce ruido en el buscador fuzzy.
            // Tomamos las primeras 4 palabras de ≥3 chars como query primaria.
            $focusedQuery = $this->extractVisionSearchQuery($description);

            $this->telegram->sendMessage(
                $chatId,
                "👁️ Producto detectado: <b>" . htmlspecialchars($description, ENT_QUOTES) . "</b>\n\n<i>Buscando en inventario…</i>"
            );

            // ── Memoria del bot ───────────────────────────────────────────────
            // Consultar aprendizajes previos ANTES de buscar en DB + llamar IA.
            // Si ya se vio esta imagen antes y el usuario lo confirmó, responder
            // instantáneamente sin gastar tokens de API.
            $cachedProductId = BotKnowledge::findProductForVision($focusedQuery);
            if ($cachedProductId) {
                $cachedResults = $this->searchService->formatProductById($cachedProductId);
                if (! empty($cachedResults)) {
                    Log::info('Photo search: memory cache hit', [
                        'chatId'     => $chatId,
                        'key'        => $focusedQuery,
                        'product_id' => $cachedProductId,
                    ]);
                    $this->handleSearch($chatId, $description, $cachedResults);
                    return;
                }
                // Producto en cache ya no existe (borrado) — continuar búsqueda normal
            }

            // ── Búsqueda normal: fuzzy → IA rerank ───────────────────────────
            // Intentar en orden: query enfocada → descripción completa → descripción + caption
            $results = $this->searchService->search($focusedQuery);

            if (empty($results) && $focusedQuery !== $description) {
                $results = $this->searchService->search($description);
            }

            if (empty($results) && $fullQuery !== $description) {
                $results = $this->searchService->search($fullQuery);
            }

            if (! empty($results)) {
                // Re-rankear con IA: filtra resultados irrelevantes y pone el mejor match
                // primero. Si solo queda uno, se muestra directo (no lista numerada).
                // Ej: [Aceite, Ventilador, Camiseta, Llaves, labubu] → [labubu]
                if (count($results) > 1) {
                    $results = $this->searchService->rerankForVision($description, $results);
                }

                // ── Aprender del resultado ────────────────────────────────────
                // Si la IA/fuzzy llegó a un único producto, guardarlo en memoria
                // para responder instantáneamente la próxima vez.
                if (count($results) === 1 && ! empty($results[0]['id'])) {
                    BotKnowledge::rememberVision(
                        key:             $focusedQuery,
                        productId:       $results[0]['id'],
                        confirmed:       false,
                        fullDescription: $description,
                    );
                }

                Log::info('Photo search hit', [
                    'chatId'      => $chatId,
                    'description' => $description,
                    'caption'     => $caption,
                    'matches'     => count($results),
                ]);
                $this->handleSearch($chatId, $description, $results);

                // Etiquetar conversación con vision_key SIEMPRE (1 o múltiples resultados).
                // Con 1 resultado → conversation step = busqueda:resultado
                // Con múltiples → step = busqueda:multiple
                // En ambos casos el vision_key viaja en data para que al final
                // (vender) se guarde como user_confirmed en BotKnowledge.
                $conv = TelegramConversation::where('chat_id', $chatId)
                    ->whereIn('step', ['busqueda:resultado', 'busqueda:multiple'])
                    ->first();
                if ($conv) {
                    $conv->update([
                        'data' => array_merge($conv->data ?? [], ['vision_key' => $focusedQuery]),
                    ]);
                }
            } else {
                $this->telegram->sendMessage(
                    $chatId,
                    "❌ Identifiqué: \"<i>" . htmlspecialchars($description, ENT_QUOTES) . "</i>\" " .
                    "pero no hay coincidencias en el inventario.\n\n" .
                    "✏️ Escribe el <b>nombre exacto</b> del producto o /listar para ver el catálogo."
                );
            }
        } catch (\Throwable $e) {
            Log::error('Photo search error', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            $this->telegram->sendMessage($chatId, "❌ Error al procesar la imagen. Intenta con otra foto o escribe el nombre del producto.");
        }
    }

    protected function handleVoiceMessage(string $chatId, array $message): void
    {
        if (Setting::get('ai_voice_enabled', '0') !== '1') {
            $this->telegram->sendMessage($chatId, "🎙️ El procesamiento de voz no está habilitado.");
            return;
        }

        $voiceData = $message['voice'] ?? $message['audio'] ?? null;
        if (!$voiceData) {
            return;
        }

        $duration = (int) ($voiceData['duration'] ?? 0);
        $maxSeconds = (int) Setting::get('whisper_max_seconds', '60');

        if ($duration > $maxSeconds) {
            $this->telegram->sendMessage($chatId, "⏱️ Audio demasiado largo. Máximo {$maxSeconds} segundos.");
            return;
        }

        try {
            $this->telegram->sendChatAction($chatId, 'typing');

            $fileId   = $voiceData['file_id'];
            $filePath = $this->telegram->getFile($fileId);
            $audio    = $this->telegram->downloadFile($filePath);
            $filename = basename($filePath) ?: 'audio.ogg';

            $transcript = $this->whisperService->transcribe($audio, $filename, $duration);

            if (empty($transcript)) {
                $this->telegram->sendMessage($chatId, "❌ No se pudo transcribir el audio.");
                return;
            }

            // If user is mid-flow, route transcript to the correct handler
            $activeConv = TelegramConversation::where('chat_id', $chatId)->where('step', '!=', 'idle')->first();
            if ($activeConv) {
                // Same out-of-context check as text dispatch
                if ($this->handleOutOfContextInput($chatId, $activeConv, $transcript)) {
                    return;
                }

                $step    = $activeConv->step;
                $fakeMsg = array_merge($message, ['text' => $transcript]);
                if (str_starts_with($step, 'venta_rapida:')) {
                    $this->saleHandler->handle($chatId, $fakeMsg);
                    return;
                }
                if (str_starts_with($step, 'nuevo:')) {
                    $this->productHandler->handle($chatId, $fakeMsg);
                    return;
                }
                if (str_starts_with($step, 'devolver:')) {
                    $this->refundHandler->handle($chatId, $fakeMsg);
                    return;
                }
                if ($step === 'agent:active') {
                    $this->agentHandler->handleVoice($chatId, $transcript);
                    return;
                }
                // busqueda:* — fall through to normal search routing below
            }

            // Same routing as text — product search first, agent only for conversational queries
            if ($this->isConversationalQuery($transcript)) {
                $this->agentHandler->handleVoice($chatId, $transcript);
            } else {
                $searchResults = $this->searchService->search($transcript);
                if (!empty($searchResults)) {
                    $this->handleSearch($chatId, $transcript, $searchResults);
                } else {
                    $this->agentHandler->handleVoice($chatId, $transcript);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Voice message error', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
            $this->telegram->sendMessage($chatId, "❌ Error al procesar el audio: " . $e->getMessage());
        }
    }

    /**
     * If user sends conversational sentence while in structured multi-step flow
     * (nuevo:*, venta_rapida:*, devolver:*), show step hint reminder and return true.
     * Caller should return without further processing. Returns false if input is normal.
     */
    protected function handleOutOfContextInput(string $chatId, TelegramConversation $conv, string $text): bool
    {
        $step = $conv->step;
        $structured = str_starts_with($step, 'nuevo:')
            || str_starts_with($step, 'venta_rapida:')
            || str_starts_with($step, 'devolver:');

        if (!$structured) {
            return false;
        }

        $lower = mb_strtolower(trim($text));

        // 1. Restart intent → auto-cancel and route to agent / show menu
        $restartKeywords = ['crear producto', 'registrar producto', 'nuevo producto', 'agregar producto',
                            'empezar de nuevo', 'cancelar todo', 'salir del proceso'];
        foreach ($restartKeywords as $kw) {
            if (str_contains($lower, $kw)) {
                $conv->delete();
                if (Setting::get('ai_chatbot_enabled') === '1') {
                    $this->telegram->sendMessage($chatId, "🔄 Proceso anterior cancelado.");
                    $this->agentHandler->handle($chatId, $text);
                } else {
                    $this->telegram->sendMessage(
                        $chatId,
                        "🔄 Proceso anterior cancelado. Usa /nuevo, /devolver, o escribe un nombre para buscar."
                    );
                }
                return true;
            }
        }

        // 2. Only block clearly out-of-context input (very long sentences, > 15 words).
        // Short voice replies like "no quiero descuento", "efectivo por favor", "40 cajas" pass through
        // to handlers (NumberParser, keyword matching) which now tolerate natural language.
        $wordCount = str_word_count($text);
        if ($wordCount <= 15) {
            return false;
        }

        $hints = [
            'nuevo:nombre'           => 'nombre del producto',
            'nuevo:categoria'        => 'categoría',
            'nuevo:precio_compra'    => 'precio de compra',
            'nuevo:precio_venta'     => 'precio de venta',
            'nuevo:cantidad'         => 'cantidad inicial',
            'nuevo:foto'             => 'foto (o escribe omitir)',
            'nuevo:confirmar'        => 'confirmación (sí/no)',
            'venta_rapida:cantidad'  => 'cantidad a vender',
            'venta_rapida:descuento' => 'descuento (número, %, o no)',
            'venta_rapida:metodo_pago' => 'método de pago (1=efectivo, 2=transferencia)',
            'venta_rapida:confirmar' => 'confirmación (sí/no)',
            'devolver:seleccionar'   => 'ID o número de venta',
            'devolver:confirmar'     => 'confirmación (1 = sí)',
        ];
        $hint = $hints[$step] ?? 'datos del flujo actual';

        $this->telegram->sendMessage(
            $chatId,
            "📝 Estás en proceso activo. Esperando: <b>{$hint}</b>.\n\n" .
            "Escribe <b>/cancelar</b> si quieres salir y empezar de nuevo."
        );
        return true;
    }

    /**
     * Extrae query de búsqueda enfocada desde descripción de visión.
     * Los modelos de visión describen en formato "[tipo] [marca] [color] [características]".
     * Las palabras clave más útiles están primero; colores y adjetivos al final son ruido.
     * Toma las primeras 4 palabras de ≥3 chars para la query primaria.
     *
     * "Llavero peluche Labubu Pop Mart blanco y azul con orejas de conejo."
     *   → "Llavero peluche Labubu Pop"  (token "labubu" → finds "Labubu" en DB)
     */
    protected function extractVisionSearchQuery(string $description): string
    {
        $clean = preg_replace('/[^\w\s]/u', ' ', $description);
        $words = preg_split('/\s+/', trim($clean), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_values(array_filter($words, fn($w) => strlen($w) >= 3));
        $selected = array_slice($words, 0, 4);
        return $selected ? implode(' ', $selected) : $description;
    }

    /**
     * Detect conversational messages that should go to AI, not product search.
     * Product names are short (1-3 words) and don't start with question/verb words.
     */
    protected function isConversationalQuery(string $text): bool
    {
        // Question mark = definitely a question
        if (str_contains($text, '?')) {
            return true;
        }

        // More than 4 words = likely a sentence, not a product name
        if (str_word_count($text) > 4) {
            return true;
        }

        // Starts with Spanish interrogative or conversational starters
        $conversationalStarters = [
            'cuánto', 'cuanto', 'cuánta', 'cuanta', 'cuántos', 'cuantos',
            'cómo', 'como', 'qué', 'que', 'cuál', 'cual',
            'dónde', 'donde', 'cuándo', 'cuando', 'por',
            'hola', 'buenos', 'buenas', 'buen',
            'necesito', 'quiero', 'quisiera',
            'hay', 'tiene', 'tenemos', 'tienen',
            'verificar', 'mostrar', 'muéstrame', 'muestrame',
            'dame', 'dime', 'ayuda', 'ayúdame',
            'vendimos', 'vendiste', 'vendió',
        ];

        $firstWord = strtolower(explode(' ', trim($text))[0]);
        return in_array($firstWord, $conversationalStarters, true);
    }
}
