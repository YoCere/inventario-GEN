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
use App\Support\BusinessTime;
use App\Support\Palabras;
use App\Support\TelegramKeyboard;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BotHandler
{
    /**
     * Pasos en los que la persona NO está llenando un formulario: está
     * charlando con el asistente o mirando resultados de una búsqueda.
     *
     * Acá lo que escribe puede ser una orden nueva ("vende 2 mates a 20",
     * "crear producto"), así que se escucha antes de seguir el camino del paso.
     * El bug que esto arregla dejaba a la dueña atrapada: una vez abierta la
     * charla con la IA, todo lo que escribía se lo contestaba la IA y el alta
     * nunca se abría.
     */
    private const PASOS_SIN_FORMULARIO = [
        'agent:active',
        'busqueda:multiple',
        'busqueda:resultado',
        'busqueda:foto',
    ];

    /** Cuántos resultados entran en una pantalla de celular sin scrollear de más. */
    private const RESULTADOS_POR_PAGINA = 8;

    /** Formas de pedir el resto de una lista de resultados. */
    private const PEDIR_MAS = ['ver mas', 'mas', 'ver todos', 'todos', 'siguiente', 'mostrar mas'];

    public function __construct(
        protected TelegramService $telegram,
        protected ProductSearchService $searchService,
        protected BotProductHandler $productHandler,
        protected BotProductEditHandler $productEditHandler,
        protected BotSaleHandler $saleHandler,
        protected BotRefundHandler $refundHandler,
        protected BotAuthHandler $authHandler,
        protected BotReportHandler $reportHandler,
        protected BotAgentHandler $agentHandler,
        protected WhisperService $whisperService,
        protected VisionService $visionService,
        protected ReminderHandler $reminderHandler,
        protected \App\Services\QuickSaleService $quickSale,
        protected IntentDetector $intents,
    ) {}

    public function dispatch(array $update): void
    {
        try {
            // Toque de un botón en línea. Llega con otra forma que un mensaje,
            // así que se atiende antes de cualquier lectura de 'message'.
            if (isset($update['callback_query'])) {
                $this->dispatchButton($update['callback_query']);
                return;
            }

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

                // Lo mismo si está cambiando la foto de un producto que ya existe.
                if ($conversation && str_starts_with($conversation->step, 'editar:')) {
                    $this->productEditHandler->handle($chatId, $message);
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
                    str_starts_with($conversation->step, 'editar:') ||
                    str_starts_with($conversation->step, 'venta_rapida:') ||
                    str_starts_with($conversation->step, 'devolver:') ||
                    str_starts_with($conversation->step, 'recordar:') ||
                    $conversation->step === 'recordatorios:gestionar' ||
                    $conversation->step === 'venta_directa:elegir'
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

                // Nadie queda atrapado: mirando resultados o charlando con la IA,
                // una orden nueva se atiende antes que el paso en curso.
                if ($this->atiendeOrdenNueva($chatId, $conversation, $text)) {
                    return;
                }

                // Route to appropriate handler based on conversation type
                if (str_starts_with($conversation->step, 'nuevo:')) {
                    $this->productHandler->handle($chatId, $message);
                    return;
                } elseif (str_starts_with($conversation->step, 'editar:')) {
                    $this->productEditHandler->handle($chatId, $message);
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
                        $this->venderDesdeFicha($chatId);
                        return;
                    } elseif ($this->pideMas($text)) {
                        $this->verMas($chatId, $conversation);
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
                    // "ver más" devuelve el resto de la lista en vez de buscar de nuevo.
                    if ($this->pideMas($text)) {
                        $this->verMas($chatId, $conversation);
                        return;
                    }
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
                } elseif ($conversation->step === 'venta_directa:elegir') {
                    // Un comando de venta completo ("vende 2 del tercero a 20") debe
                    // interceptarse ANTES del handler de selección; una respuesta simple
                    // ("2"/"dos") no dispara SELL_VERB y cae al handleDirectPick.
                    if ($this->saleHandler->tryQuickSell($chatId, $text)) {
                        return;
                    }
                    $this->saleHandler->handleDirectPick($chatId, $conversation, trim($text));
                    return;
                }
            }

            // Free text routing
            // Interceptor de venta directa (determinista) antes del agente IA.
            if ($this->saleHandler->tryQuickSell($chatId, $text)) {
                return;
            }

            // Qué quiere hacer, antes de asumir que escribió el nombre de un producto.
            if ($this->tryIntent($chatId, $text)) {
                return;
            }

            if (strtolower($text) === 'vender') {
                Log::info('User requested quick sale', ['chatId' => $chatId]);
                $this->handleQuickSaleFromSearch($chatId);
            } elseif ($this->esPregunta($text)) {
                // Pregunta → al asistente sin pasar por el catálogo
                if (Setting::get('ai_chatbot_enabled') === '1') {
                    Log::info('Conversational query → agent', ['chatId' => $chatId]);
                    $this->agentHandler->handle($chatId, $text);
                } else {
                    $this->telegram->sendMessage($chatId, "❓ Escribe el nombre de un producto para buscarlo.");
                }
            } else {
                // No preguntó nada: primero el catálogo, aunque la frase sea larga.
                // Recién si no hay ninguna coincidencia interviene el asistente.
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

    /**
     * Muestra lo que se encontró.
     *
     * @param array<string, mixed> $recuerdos datos que la búsqueda tiene que
     *        seguir arrastrando: de qué foto salió, qué describió la visión.
     */
    protected function handleSearch(string $chatId, string $text, ?array $results = null, array $recuerdos = []): void
    {
        $results ??= $this->searchService->search($text);

        if (empty($results)) {
            $this->telegram->sendMessage(
                $chatId,
                "❌ No se encontró ningún producto con \"<i>{$text}</i>\"",
                'HTML',
                $this->botonesSinResultado($recuerdos)
            );
            return;
        }

        $recuerdos['busqueda'] = $text;

        if (count($results) === 1) {
            Log::info('Single result found', ['productId' => $results[0]['id'] ?? null, 'chatId' => $chatId]);
            $this->mostrarFicha($chatId, $results[0], $recuerdos);
            return;
        }

        $this->mostrarLista($chatId, $results, $recuerdos);
    }

    /**
     * La ficha de un producto, con sus salidas a la vista.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $recuerdos
     */
    protected function mostrarFicha(string $chatId, array $result, array $recuerdos = []): void
    {
        $productId = $result['id'] ?? null;

        if ($productId) {
            $conversation = TelegramConversation::getOrCreate($chatId);
            $conversation->update([
                'step'       => 'busqueda:resultado',
                'data'       => array_merge($recuerdos, ['product_id' => $productId]),
                'expires_at' => now()->addMinutes(5),
            ]);
        }

        // El texto numerado se queda: quien ya aprendió a escribir "1" para
        // vender no tiene que aprender nada nuevo por los botones.
        $message = $result['message'] . "\n\n";
        $message .= "<b>Opciones:</b>\n";
        $message .= "1️⃣ Vender\n";
        $message .= "2️⃣ Buscar otro producto";

        $this->sendProductCard($chatId, $result, $message, $this->botonesDeFicha($result, $recuerdos));
    }

    /**
     * La lista de coincidencias, de a una pantalla.
     *
     * @param array<int, array<string, mixed>> $results
     * @param array<string, mixed> $recuerdos
     */
    protected function mostrarLista(string $chatId, array $results, array $recuerdos = [], int $yaMostrados = 0): void
    {
        $texto  = (string) ($recuerdos['busqueda'] ?? '');
        $desde  = max(0, $yaMostrados);
        $hasta  = min(count($results), $desde + self::RESULTADOS_POR_PAGINA);
        $quedan = count($results) - $hasta;

        $conversation = TelegramConversation::getOrCreate($chatId);
        $conversation->update([
            'step'       => 'busqueda:multiple',
            'data'       => array_merge($recuerdos, [
                'results'   => $results,
                'mostrados' => $hasta,
                'busqueda'  => $texto,
            ]),
            'expires_at' => now()->addMinutes(5),
        ]);

        $message = $desde === 0
            ? "📦 <b>Encontré " . count($results) . " productos para: {$texto}</b>\n\n"
            : "📦 <b>Sigue la lista de: {$texto}</b>\n\n";

        $teclado = TelegramKeyboard::make();
        $fila = [];

        for ($i = $desde; $i < $hasta; $i++) {
            $numero = $i + 1;
            $item   = $results[$i];
            $codigo = $item['sku'] ?? '';

            // Código y precio en la misma línea: dos productos que se llaman
            // igual se tienen que poder distinguir sin abrir ninguno.
            $message .= "{$numero}. <b>{$item['name']}</b>\n";
            $message .= "    Código {$codigo} · Precio {$item['price']}\n";

            $fila[(string) $numero] = 'buscar:elegir:' . $numero;

            if (count($fila) === 4) {
                $teclado->row($fila);
                $fila = [];
            }
        }

        if ($fila !== []) {
            $teclado->row($fila);
        }

        $salidas = [];
        if ($quedan > 0) {
            $message .= "\n<i>Quedan {$quedan} más.</i>\n";
            $salidas["📋 Ver más ({$quedan})"] = 'buscar:mas';
        }
        $salidas['🔍 Buscar otro'] = 'buscar:otro';
        $teclado->row($salidas);

        $message .= "\n<i>Tocá el número del producto, o escribilo (ej: 1).</i>";

        $this->telegram->sendMessage($chatId, $message, 'HTML', $teclado->toArray());
    }

    /** ¿Está pidiendo el resto de la lista? */
    protected function pideMas(string $text): bool
    {
        $sinTildes = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($text)));
        $limpio    = preg_replace('/[^a-z0-9\s]/', '', $sinTildes) ?? '';
        $limpio    = trim(preg_replace('/\s+/', ' ', $limpio) ?? '');

        return in_array($limpio, self::PEDIR_MAS, true);
    }

    /** Devuelve el tramo siguiente de la última lista de resultados. */
    protected function verMas(string $chatId, TelegramConversation $conversation): void
    {
        $data    = $conversation->data ?? [];
        $results = $data['results'] ?? [];

        if (empty($results)) {
            $this->telegram->sendMessage($chatId, "No tengo una lista abierta. Escribí el nombre o el código del producto.");
            return;
        }

        $mostrados = (int) ($data['mostrados'] ?? 0);

        if ($mostrados >= count($results)) {
            $this->telegram->sendMessage($chatId, "Ya te mostré todo lo que encontré. Escribí otro nombre para buscar de nuevo.");
            return;
        }

        unset($data['results'], $data['mostrados']);
        $this->mostrarLista($chatId, $results, $data, $mostrados);
    }

    /**
     * Botones de la ficha. Vender y Editar llevan el producto que se está
     * mirando; el de editar usa el dato que espera el flujo de edición.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $recuerdos
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    protected function botonesDeFicha(array $result, array $recuerdos): array
    {
        $teclado = TelegramKeyboard::make()->row([
            '💵 Vender' => 'buscar:vender',
            '✏️ Editar' => 'edit:abrir:' . (int) ($result['id'] ?? 0),
        ]);

        $salidas = [];
        $quedan = count($recuerdos['results'] ?? []) - (int) ($recuerdos['mostrados'] ?? 0);
        if ($quedan > 0) {
            $salidas["📋 Ver más ({$quedan})"] = 'buscar:mas';
        }
        $salidas['🔍 Buscar otro'] = 'buscar:otro';
        $teclado->row($salidas);

        if (! empty($recuerdos['foto_file_id'])) {
            $teclado->button('🆕 Ninguno: crear producto nuevo', 'buscar:nuevo');
        }

        return $teclado->toArray();
    }

    /**
     * Cuando no hay coincidencias y la búsqueda salió de una foto, la salida es
     * dar de alta el producto con esa misma foto.
     *
     * @param array<string, mixed> $recuerdos
     * @return array<int, array<int, array{text: string, callback_data: string}>>|null
     */
    protected function botonesSinResultado(array $recuerdos): ?array
    {
        if (empty($recuerdos['foto_file_id'])) {
            return null;
        }

        return TelegramKeyboard::make()
            ->button('🆕 Ninguno: crear producto nuevo', 'buscar:nuevo')
            ->toArray();
    }

    protected function handleMultipleResultSelection(string $chatId, TelegramConversation $conversation, string $input): void
    {
        $data    = $conversation->data ?? [];
        $results = $data['results'] ?? [];
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

        // La lista entera viaja con la ficha: así "ver más" sigue valiendo
        // después de abrir uno, y de qué foto salió no se pierde.
        $this->mostrarFicha($chatId, $selectedResult, $data);
    }

    /**
     * Send product card with image if available, fallback to text-only message.
     * Telegram photo caption limit: 1024 chars. If exceeds, splits photo + follow-up text.
     */
    protected function sendProductCard(string $chatId, array $product, string $message, ?array $keyboard = null): void
    {
        $imagePath = $product['image_path'] ?? null;

        // No image → plain text
        if (!$imagePath || !Storage::disk('public')->exists($imagePath)) {
            $this->telegram->sendMessage($chatId, $message, 'HTML', $keyboard);
            return;
        }

        try {
            // Telegram photo caption max 1024 chars
            if (mb_strlen($message) <= 1024) {
                $this->telegram->sendPhoto($chatId, $imagePath, $message, $keyboard);
            } else {
                // Photo with truncated caption + full text follow-up
                $this->telegram->sendPhoto($chatId, $imagePath, $product['name'] ?? '');
                $this->telegram->sendMessage($chatId, $message, 'HTML', $keyboard);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send product photo, fallback to text', [
                'chat_id' => $chatId,
                'image_path' => $imagePath,
                'error' => $e->getMessage(),
            ]);
            $this->telegram->sendMessage($chatId, $message, 'HTML', $keyboard);
        }
    }

    /**
     * Vender el producto que la persona está mirando.
     *
     * Si esa ficha salió de una foto, elegir vender es la confirmación de que
     * la foto era ese producto: se guarda como aprendido para no volver a
     * gastar visión la próxima vez.
     */
    protected function venderDesdeFicha(string $chatId): void
    {
        $conversation = TelegramConversation::where('chat_id', $chatId)
            ->where('step', 'busqueda:resultado')
            ->first();

        $data      = $conversation->data ?? [];
        $visionKey = $data['vision_key'] ?? null;
        $productId = $data['product_id'] ?? null;

        if ($visionKey && $productId) {
            BotKnowledge::rememberVision($visionKey, (int) $productId, confirmed: true);
        }

        $this->handleQuickSaleFromSearch($chatId);
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
            '/deshacer' => $this->cmdDeshacer($chatId),
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
        $canFinance = $user?->can('finance.view') ?? false;

        $message = "<b>📚 Ayuda - Comandos disponibles</b>\n\n" .
            "/stock — Ver productos en stock crítico\n" .
            "/ventas — Resumen de ventas de hoy\n" .
            "/nuevo — Registrar un nuevo producto\n" .
            "/listar — Listar productos (todas categorías o filtrar)\n" .
            "/devolver — Procesar devoluciones\n" .
            "/deshacer — Anular tu última venta\n" .
            "/recordar — Crear un recordatorio personal\n" .
            "/recordatorios — Ver y cancelar tus recordatorios\n";

        // Reportes gateados por permiso finance.view (gerencia financiera, no operación diaria)
        if ($canFinance) {
            $message .= "/reportes — Reportes del negocio (libro diario, top ventas, ganancia, etc.) <i>finanzas</i>\n";
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
        // created_at es un instante UTC: el "día" local se traduce a un rango UTC.
        $today = BusinessTime::today();
        $sales = \App\Models\Sale::whereBetween('created_at', BusinessTime::dayRangeUtc())
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
     * /deshacer — anula la última venta del usuario (admin: la última global).
     * Restaura stock y reversa contabilidad vía QuickSaleService::voidLast().
     */
    protected function cmdDeshacer(string $chatId): void
    {
        $user = $this->authHandler->getAuthenticatedUser($chatId);
        if (! $user) {
            $this->telegram->sendMessage($chatId, "❌ Sesión no válida.");
            return;
        }

        try {
            $sale = $this->quickSale->voidLast($user);
            $this->telegram->sendMessage($chatId, "↩️ Venta <b>{$sale->invoice_number}</b> anulada. Stock restaurado.");
        } catch (\RuntimeException $e) {
            $this->telegram->sendMessage($chatId, "❌ " . $e->getMessage());
        }
    }

    /**
     * /reportes — gate por permiso finance.view. Staff sin ese permiso no ve cifras financieras del negocio.
     */
    protected function cmdReports(string $chatId): void
    {
        $user = $this->authHandler->getAuthenticatedUser($chatId);

        if (! $user || ! $user->can('finance.view')) {
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

            // De qué foto salió esta búsqueda viaja con todo lo que se muestre:
            // si al final ninguno era el producto, el alta la reusa y la persona
            // no tiene que sacar la foto otra vez.
            $recuerdos = ['foto_file_id' => $fileId];
            $this->recordarFotoBuscada($chatId, $fileId);

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
                        $this->handleSearch($chatId, $caption, $results, $recuerdos);
                        return;
                    }
                }

                $this->telegram->sendMessage(
                    $chatId,
                    "❌ No pude identificar el producto en la imagen.\n\n" .
                    "✏️ Escribe el <b>nombre del producto</b> para buscarlo directamente.",
                    'HTML',
                    $this->botonesSinResultado($recuerdos)
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
                    $this->handleSearch($chatId, $description, $cachedResults, array_merge($recuerdos, [
                        'vision_key' => $focusedQuery,
                    ]));
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
                // El vision_key viaja con los resultados (uno o varios): al
                // vender, eso es lo que se guarda como aprendido.
                $this->handleSearch($chatId, $description, $results, array_merge($recuerdos, [
                    'vision_key' => $focusedQuery,
                ]));
            } else {
                $this->telegram->sendMessage(
                    $chatId,
                    "❌ Identifiqué: \"<i>" . htmlspecialchars($description, ENT_QUOTES) . "</i>\" " .
                    "pero no hay coincidencias en el inventario.\n\n" .
                    "✏️ Escribe el <b>nombre exacto</b> del producto, o tocá el botón para darlo de alta.",
                    'HTML',
                    $this->botonesSinResultado($recuerdos)
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

    /**
     * Guarda de qué foto vino la búsqueda, para que el botón de dar de alta la
     * encuentre aunque no se haya mostrado ningún resultado.
     */
    protected function recordarFotoBuscada(string $chatId, string $fileId): void
    {
        $conversation = TelegramConversation::getOrCreate($chatId);

        $conversation->update([
            'step'       => 'busqueda:foto',
            'data'       => array_merge($conversation->data ?? [], ['foto_file_id' => $fileId]),
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    /**
     * Entrada única de los botones en línea del bot.
     *
     * Acá vive todo lo que vale para CUALQUIER flujo con botones, para que un
     * flujo nuevo solo tenga que agregar su prefijo al ruteo del final:
     *  - el que toca el botón tiene que ser el dueño del chat donde vive ese
     *    botón (nadie opera el registro de otra persona reenviando un mensaje);
     *  - pasa por el mismo portón de acceso y la misma pausa del bot que los
     *    mensajes de texto, sin abrir una puerta nueva;
     *  - el toque siempre se confirma antes de trabajar, así el botón no queda
     *    girando en el celular aunque después algo falle.
     */
    protected function dispatchButton(array $callbackQuery): void
    {
        $touchId = (string) ($callbackQuery['id'] ?? '');
        $actorId = $callbackQuery['from']['id'] ?? null;
        // Chat donde el bot mandó el mensaje con botones = dueño de ese registro.
        $ownerId = $callbackQuery['message']['chat']['id'] ?? null;
        $payload = trim((string) ($callbackQuery['data'] ?? ''));

        if ($touchId === '' || $actorId === null || $ownerId === null || $payload === '') {
            return;
        }

        $actorChatId = (string) $actorId;
        $ownerChatId = (string) $ownerId;

        if ($actorChatId !== $ownerChatId) {
            Log::warning('Botón de Telegram tocado por un chat ajeno', [
                'actor' => $actorChatId,
                'owner' => $ownerChatId,
            ]);
            $this->telegram->answerCallbackQuery($touchId, 'Este botón no es tuyo.', true);
            return;
        }

        if (!$this->authHandler->isAuthenticated($actorChatId)) {
            $this->telegram->answerCallbackQuery(
                $touchId,
                'Tu sesión venció. Escribí un mensaje para entrar de nuevo.',
                true
            );
            return;
        }

        $adminChatId = Setting::get('telegram_admin_chat_id', '');
        if (Setting::get('telegram_bot_paused', '0') === '1' && $actorChatId !== $adminChatId) {
            $this->telegram->answerCallbackQuery($touchId, 'El bot está detenido por ahora.', true);
            return;
        }

        $this->telegram->answerCallbackQuery($touchId);

        $messageId = isset($callbackQuery['message']['message_id'])
            ? (int) $callbackQuery['message']['message_id']
            : null;

        // Primer tramo del dato = flujo dueño del botón.
        [$flow, $action] = array_pad(explode(':', $payload, 2), 2, '');

        match ($flow) {
            'prod'   => $this->productHandler->handleButton($actorChatId, $action, $messageId),
            'edit'   => $this->productEditHandler->handleButton($actorChatId, $action, $messageId),
            'buscar' => $this->botonDeBusqueda($actorChatId, $action),
            default  => Log::warning('Botón de Telegram de un flujo desconocido', ['data' => $payload]),
        };
    }

    /**
     * Botones de una búsqueda: vender, elegir uno de la lista, ver el resto,
     * buscar otra cosa, o dar de alta lo que no está.
     */
    protected function botonDeBusqueda(string $chatId, string $action): void
    {
        [$que, $detalle] = array_pad(explode(':', $action, 2), 2, '');

        $conversation = TelegramConversation::where('chat_id', $chatId)->first();
        $data = $conversation->data ?? [];

        match ($que) {
            'vender' => $this->venderDesdeFicha($chatId),
            'elegir' => $this->elegirDeLaLista($chatId, $conversation, $detalle),
            'mas'    => $conversation
                ? $this->verMas($chatId, $conversation)
                : $this->telegram->sendMessage($chatId, "Esa lista ya se cerró. Escribí el nombre del producto otra vez."),
            'otro'   => $this->pedirOtraBusqueda($chatId, $conversation),
            'nuevo'  => $this->altaConLaFotoBuscada($chatId, $data['foto_file_id'] ?? null),
            default  => Log::warning('Botón de búsqueda desconocido', ['accion' => $action]),
        };
    }

    protected function elegirDeLaLista(string $chatId, ?TelegramConversation $conversation, string $numero): void
    {
        if (! $conversation || empty($conversation->data['results'] ?? [])) {
            $this->telegram->sendMessage($chatId, "Esa lista ya se cerró. Escribí el nombre del producto otra vez.");
            return;
        }

        $this->handleMultipleResultSelection($chatId, $conversation, $numero);
    }

    protected function pedirOtraBusqueda(string $chatId, ?TelegramConversation $conversation): void
    {
        $conversation?->delete();

        $this->telegram->sendMessage(
            $chatId,
            "🔍 ¿Qué producto buscamos? Escribí el <b>nombre</b> o el <b>código</b>."
        );
    }

    /**
     * Arranca el alta con la foto que la persona ya mandó, para no pedírsela de
     * nuevo cuando la búsqueda por imagen no encontró el producto.
     */
    protected function altaConLaFotoBuscada(string $chatId, ?string $fileId): void
    {
        $ruta = $fileId ? $this->guardarFotoDeTelegram($fileId) : null;

        if ($ruta !== null) {
            $this->telegram->sendMessage($chatId, "📷 Uso la foto que me mandaste para este producto.");
        } elseif ($fileId !== null) {
            $this->telegram->sendMessage($chatId, "No pude recuperar esa foto, así que te la voy a pedir más adelante.");
        }

        $this->productHandler->start($chatId);

        if ($ruta === null) {
            return;
        }

        // Se deja con la misma forma que usa el paso de fotos del alta, para que
        // el resumen y el guardado la encuentren sin saber de dónde salió.
        $conversation = TelegramConversation::getOrCreate($chatId);
        $data = $conversation->data ?? [];
        $data['fotos']     = [$ruta];
        $data['foto_path'] = $ruta;

        $conversation->update([
            'data'       => $data,
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    /**
     * Baja una foto de Telegram al disco público, con el mismo nombre y lugar
     * que las del alta. Devuelve la ruta guardada, o null si no se pudo.
     */
    protected function guardarFotoDeTelegram(string $fileId): ?string
    {
        try {
            $contenido = $this->telegram->downloadFile($this->telegram->getFile($fileId));

            if (empty($contenido)) {
                return null;
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $tipo  = finfo_buffer($finfo, $contenido);
            finfo_close($finfo);

            // Lo que no sea imagen no sirve: el procesamiento posterior (las
            // variantes para la tienda) solo entiende imágenes.
            $extension = match ($tipo) {
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                default      => null,
            };

            if ($extension === null) {
                return null;
            }

            Storage::disk('public')->makeDirectory('products');
            $ruta = 'products/' . Str::uuid() . '.' . $extension;
            Storage::disk('public')->put($ruta, $contenido);

            return $ruta;
        } catch (\Throwable $e) {
            Log::warning('No se pudo guardar la foto de la búsqueda', [
                'file_id' => $fileId,
                'error'   => $e->getMessage(),
            ]);

            return null;
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

                // Lo mismo que por texto: dictar una orden nueva mientras se
                // charla con la IA no puede quedar tapado por la charla.
                if ($this->atiendeOrdenNueva($chatId, $activeConv, $transcript)) {
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
                if (str_starts_with($step, 'editar:')) {
                    $this->productEditHandler->handle($chatId, $fakeMsg);
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
                if ($step === 'venta_directa:elegir') {
                    if ($this->saleHandler->tryQuickSell($chatId, $transcript)) {
                        return;
                    }
                    $this->saleHandler->handleDirectPick($chatId, $activeConv, $transcript);
                    return;
                }
                // busqueda:* — fall through to normal search routing below
            }

            if ($this->saleHandler->tryQuickSell($chatId, $transcript)) {
                return;
            }

            // Mismo criterio que por texto: primero qué quiere hacer, después buscar.
            if ($this->tryIntent($chatId, $transcript)) {
                return;
            }

            // Same routing as text — product search first, agent only for conversational queries
            if ($this->esPregunta($transcript)) {
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
     * Atiende el mensaje si lo que pide es empezar algo (hoy: dar de alta un
     * producto). Devuelve true cuando ya lo atendió.
     *
     * Por qué antes de buscar: "crear un producto" no es el nombre de nada, y la
     * búsqueda difusa igual encontraba parecidos y mostraba una ficha ajena.
     */
    protected function tryIntent(string $chatId, string $text): bool
    {
        $intencion = $this->intents->detect($text);

        if (! $intencion) {
            return false;
        }

        if ($intencion['intent'] === IntentDetector::EDICION_PRODUCTO) {
            $this->abrirEdicion($chatId, $intencion['resto']);

            return true;
        }

        if ($intencion['intent'] === IntentDetector::ALTA_PRODUCTO) {
            $this->startProductCreation($chatId, $text, $intencion['resto']);

            return true;
        }

        return false;
    }

    /**
     * Mientras el estado en el que está la persona no sea un formulario,
     * cualquier cosa que escriba puede ser una orden nueva. Se escucha en el
     * mismo orden que en el texto libre: vender, dar de alta, corregir.
     *
     * Antes de esto, abrir una charla con el asistente tapaba todo lo demás: la
     * persona escribía "Crear producto" y le contestaba la IA, una y otra vez.
     */
    protected function atiendeOrdenNueva(string $chatId, TelegramConversation $conversation, string $text): bool
    {
        if (! in_array($conversation->step, self::PASOS_SIN_FORMULARIO, true)) {
            return false;
        }

        if ($this->saleHandler->tryQuickSell($chatId, $text)) {
            return true;
        }

        return $this->tryIntent($chatId, $text);
    }

    /**
     * Pasa el pedido de corregir un producto al flujo de edición.
     *
     * El flujo vive en otra clase; mientras no esté, se dice con palabras lo
     * que todavía no se puede hacer, en vez de dejar a la persona esperando.
     */
    protected function abrirEdicion(string $chatId, string $resto): void
    {
        Log::info('Intención de editar un producto', ['chatId' => $chatId, 'resto' => $resto]);

        $this->productEditHandler->start($chatId, $resto);
    }

    /**
     * Abre el alta. Si el mensaje además trae datos ("crear producto mate
     * imperial 250 350 20") y el asistente está encendido, se los pasa al
     * agente, que sabe repartirlos en los campos; si no, arranca el paso a paso.
     */
    protected function startProductCreation(string $chatId, string $textoOriginal, string $resto): void
    {
        $traeDatos = $resto !== '' && Palabras::contar($resto) >= 2;

        if ($traeDatos && Setting::get('ai_chatbot_enabled') === '1') {
            Log::info('Intención de alta con datos → agente', ['chatId' => $chatId]);
            $this->agentHandler->handle($chatId, $textoOriginal);

            return;
        }

        Log::info('Intención de alta → paso a paso', ['chatId' => $chatId]);
        $this->productHandler->start($chatId);
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
            || str_starts_with($step, 'editar:')
            || str_starts_with($step, 'venta_rapida:')
            || str_starts_with($step, 'devolver:');

        if (!$structured) {
            return false;
        }

        $lower = mb_strtolower(trim($text));

        // 1. Pidió empezar de nuevo → se cancela lo anterior y se arranca lo pedido.
        //    La intención sale del mismo detector que usa el ruteo principal: una
        //    sola lista de frases, no dos que hay que acordarse de sincronizar.
        //
        //    A propósito solo el alta corta el proceso en curso: dentro del alta,
        //    "cambiá el nombre" o "corregí el precio" se refieren a lo que se
        //    está cargando ahora, no a un producto que ya existe, y cortarlo
        //    para irse a otra pantalla le borraría a la persona lo que ya dictó.
        $intencion = $this->intents->detect($text);
        $pideUnAlta = $intencion !== null && $intencion['intent'] === IntentDetector::ALTA_PRODUCTO;
        $frasesDeSalida = ['empezar de nuevo', 'cancelar todo', 'salir del proceso'];
        $quiereSalir = false;

        foreach ($frasesDeSalida as $frase) {
            if (str_contains($lower, $frase)) {
                $quiereSalir = true;
                break;
            }
        }

        if ($pideUnAlta || $quiereSalir) {
            $conv->delete();
            $this->telegram->sendMessage($chatId, "🔄 Proceso anterior cancelado.");

            if ($pideUnAlta) {
                $this->startProductCreation($chatId, $text, $intencion['resto']);
            } elseif (Setting::get('ai_chatbot_enabled') === '1') {
                $this->agentHandler->handle($chatId, $text);
            } else {
                $this->telegram->sendMessage(
                    $chatId,
                    "Usa /nuevo, /devolver, o escribe un nombre para buscar."
                );
            }

            return true;
        }

        // 2. Only block clearly out-of-context input (very long sentences, > 15 words).
        // Short voice replies like "no quiero descuento", "efectivo por favor", "40 cajas" pass through
        // to handlers (NumberParser, keyword matching) which now tolerate natural language.
        $wordCount = Palabras::contar($text);
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
            'nuevo:revisar'          => 'que toques Guardar o Editar',
            'nuevo:editar'           => 'que elijas qué dato corregir',
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
        if ($this->esPregunta($text)) {
            return true;
        }

        // Varias palabras y ningún arranque de pregunta: probablemente sea una
        // frase. Se cuenta con Palabras y no con str_word_count porque esa
        // función parte "chaqueño" en dos y le sumaba una palabra de más.
        return Palabras::contar($text) > 4;
    }

    /**
     * ¿Está preguntando algo?
     *
     * Esto es lo único que manda un mensaje al asistente sin pasar por el
     * catálogo. El largo de la frase NO alcanza para decidirlo: "sombrero
     * chaqueño costura a mano" es el nombre de un producto, y mandarlo a la IA
     * por tener cinco palabras es lo que hacía parecer que el bot no encontraba
     * medio catálogo.
     */
    protected function esPregunta(string $text): bool
    {
        // Question mark = definitely a question
        if (str_contains($text, '?')) {
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
