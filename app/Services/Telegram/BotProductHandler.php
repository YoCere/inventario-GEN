<?php

namespace App\Services\Telegram;

use App\Models\Product;
use App\Models\TelegramConversation;
use App\Models\Unit;
use App\Settings\ProductIntakeFields;
use App\Shop\Models\ProductImage;
use App\Shop\Services\ImageProcessor;
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
     * Tope de fotos por producto.
     *
     * Cinco alcanza para mostrar los colores de un mismo artículo y mantiene
     * acotado el trabajo de procesar variantes y el peso del catálogo público,
     * que se ve desde el celular de los clientes.
     */
    private const MAX_FOTOS = 5;

    /**
     * Datos corregibles desde el resumen, en el orden en que se muestran.
     * La clave es el campo guardado; el valor, el nombre que lee la persona.
     */
    private const CAMPOS_EDITABLES = [
        'nombre'        => 'Nombre',
        'categoria'     => 'Categoría',
        'unidad'        => 'Cómo se vende',
        'precio_compra' => 'Precio de compra',
        'precio_venta'  => 'Precio de venta',
        'cantidad'      => 'Cantidad',
        'stock_minimo'  => 'Avisar cuando queden',
        'foto'          => 'Fotos',
    ];

    /**
     * Stock mínimo ofrecido con botones. Son los números que la gente contesta
     * de verdad; escribir otro sigue valiendo.
     */
    private const STOCK_MINIMO_SUGERIDO = [1, 3, 5, 10];

    public function __construct(
        protected TelegramService $telegram,
        protected ProductService $productService,
        protected CategoryService $categoryService,
        protected RespuestaNatural $respuesta,
        protected InterpretePaso $interprete,
        protected UnidadDeVenta $unidades,
        protected PasosDelAlta $pasos,
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
            'nuevo:unidad' => $this->askUnidad($chatId, $conversation, $text),
            'nuevo:precio_compra' => $this->askPrecioCompra($chatId, $conversation, $text),
            'nuevo:precio_venta' => $this->askPrecioVenta($chatId, $conversation, $text),
            'nuevo:cantidad' => $this->askCantidad($chatId, $conversation, $text),
            'nuevo:stock_minimo' => $this->askStockMinimo($chatId, $conversation, $text),
            'nuevo:foto' => $this->askFoto($chatId, $conversation, $message),
            'nuevo:confirmar' => $this->confirm($chatId, $conversation, $text),
            default => $this->telegram->sendMessage($chatId, "❓ Estado desconocido. /cancelar y reintenta."),
        };
    }

    private function askNombre(string $chatId, TelegramConversation $conversation, string $nombre): void
    {
        // "el nombre es mate imperial" → "mate imperial". Si la limpieza se lleva
        // todo (respuesta de una sola palabra, tipo "cinto"), vale el original.
        $nombre = $this->respuesta->valor($nombre, ['nombre', 'llama', 'titulo']) ?: trim($nombre);

        if (empty($nombre) || strlen($nombre) < 3) {
            $this->telegram->sendMessage($chatId, "❌ El nombre debe tener al menos 3 caracteres.");
            return;
        }

        $data = $conversation->data ?? [];
        $data['nombre'] = $nombre;

        $this->telegram->sendMessage($chatId, "📦 Nombre: <b>{$nombre}</b>");

        // Qué se pregunta después no se decide acá: lo dice ProductIntakeFields,
        // que es lo que la dueña configura en Ajustes.
        $this->advanceToNextMissingStep($chatId, $conversation, $data);
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

        // ── Lo que dijo, sin la cáscara ──────────────────────────────────────
        // "ponele mates" → "mates". Antes la frase entera se buscaba tal cual y
        // terminaba ofreciendo crear una categoría llamada "ponele mates".
        $valor = $this->respuesta->valor($input, ['categoria', 'rubro', 'seccion']);

        // ── Lo encontrado, si se entendió algo ───────────────────────────────
        $category = $valor !== '' ? $this->findCategoryByText($valor) : null;

        if ($category) {
            $this->setCategoryAndAdvance($chatId, $conversation, $data, $category);
            return;
        }

        // ── Por letras no coincidió: que lo piense ───────────────────────────
        // "las cosas para tomar mate" no comparte una sola letra útil con
        // "Mates", y cualquier persona sabe que es eso. Se consulta acá y no
        // antes a propósito: solo cuando lo barato ya falló.
        $category = $this->interpretarCategoriaConIa($input);

        if ($category) {
            $this->setCategoryAndAdvance($chatId, $conversation, $data, $category);
            return;
        }

        // ── No se entendió: la segunda vez hay que preguntar DISTINTO ────────
        // Repetir el mismo texto es lo que hace que la persona crea que el bot se
        // colgó y abandone. Al segundo fallo seguido se pasa a botones.
        $data['categoria_fallos'] = ($data['categoria_fallos'] ?? 0) + 1;

        if ($valor !== '') {
            $intentos = $data['categoria_intentos'] ?? [];
            $intentos[] = $valor;
            $data['categoria_intentos'] = array_slice($intentos, -4);
        }

        if ($data['categoria_fallos'] >= 2) {
            unset($data['categoria_pending']);
            $conversation->update(['data' => $data, 'expires_at' => now()->addMinutes(30)]);
            $this->ofrecerCategoriasConBotones($chatId, $data);

            return;
        }

        if ($valor === '') {
            // Puro relleno: no hay dato que buscar. Volver a preguntar es correcto;
            // ofrecer crear algo con esa frase, no.
            $conversation->update(['data' => $data, 'expires_at' => now()->addMinutes(30)]);
            $this->telegram->sendMessage(
                $chatId,
                "🤔 No te entendí la categoría.\n\n" . $this->buildCategoryPrompt()
            );

            return;
        }

        // ── Primer fallo con un nombre concreto → ofrecer crearla ────────────
        $inputLow = mb_strtolower($valor);

        $similar = Category::whereRaw('LOWER(name) LIKE ?', ['%' . $inputLow . '%'])
            ->orderBy('name')
            ->limit(3)
            ->get();

        $data['categoria_pending'] = $valor;
        $conversation->update(['data' => $data, 'expires_at' => now()->addMinutes(30)]);

        $input = $valor; // lo que se ofrece crear es el dato limpio, no la frase
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

    /**
     * Le pregunta al modelo a cuál de las categorías existentes se refería.
     *
     * Se le manda la frase ENTERA, no la limpia: el contexto ("las cosas para
     * tomar mate") es justamente lo que le permite acertar. Lo que conteste se
     * verifica contra la base, así que nunca puede inventar una categoría nueva
     * ni escribir mal una que existe.
     *
     * Elegida la categoría, el flujo sigue sin pedir confirmación. Es a
     * propósito: sumar un "¿te referís a Mates?" devolvería justo lo que
     * veníamos a sacar, una pregunta de más. La red por si el modelo se
     * equivoca es el resumen final, que muestra la categoría y tiene botón
     * Editar; además el bot avisa en el momento con "✅ Categoría: Mates".
     */
    private function interpretarCategoriaConIa(string $frase): ?Category
    {
        $nombres = Category::orderBy('name')->pluck('name')->all();

        if ($nombres === []) {
            return null;
        }

        $elegida = $this->interprete->elegirOpcion($frase, '¿Cuál es la categoría?', $nombres);

        if ($elegida === null) {
            return null;
        }

        $categoria = Category::whereRaw('LOWER(name) = LOWER(?)', [$elegida])->first();

        if ($categoria) {
            Log::info('Categoría resuelta por la IA', [
                'dijo' => mb_substr($frase, 0, 60),
                'entendio' => $categoria->name,
            ]);
        }

        return $categoria;
    }

    /**
     * Segunda vez que no se entiende: en vez de repetir la pregunta, se muestran
     * las categorías que ya existen como botones, ordenadas por parecido con lo
     * que la persona venía intentando decir.
     *
     * @param array<string, mixed> $data
     */
    private function ofrecerCategoriasConBotones(string $chatId, array $data): void
    {
        $intentos = $data['categoria_intentos'] ?? [];
        $sugeridas = $this->categoriasParecidas($intentos);

        if ($sugeridas->isEmpty()) {
            // Sin categorías cargadas todavía: no hay nada que ofrecer.
            $this->telegram->sendMessage(
                $chatId,
                "🤔 Seguimos sin entendernos.\n\n" .
                "Escribí solo el nombre de la categoría, por ejemplo: <b>Cintos</b>."
            );

            return;
        }

        $teclado = TelegramKeyboard::make();

        foreach ($sugeridas as $categoria) {
            $teclado->button($categoria->name, self::BOTON . 'cat:' . $categoria->id);
        }

        $ultimoIntento = $intentos !== [] ? (string) end($intentos) : '';

        if ($ultimoIntento !== '') {
            $teclado->button("➕ Crear «{$ultimoIntento}»", self::BOTON . 'catnueva');
        }

        $teclado->button('✖️ Dejarlo por ahora', self::BOTON . 'cancelar');

        $this->telegram->sendMessage(
            $chatId,
            "Mejor elegí tocando. ¿En cuál va este producto?",
            'HTML',
            $teclado->toArray()
        );
    }

    /**
     * Las categorías más parecidas a lo que la persona intentó decir. Sin
     * intentos legibles, las primeras por orden alfabético: algo hay que ofrecer.
     *
     * @param  array<int, string>  $intentos
     * @return \Illuminate\Support\Collection<int, Category>
     */
    private function categoriasParecidas(array $intentos): \Illuminate\Support\Collection
    {
        $categorias = Category::orderBy('name')->get();

        if ($categorias->isEmpty() || $intentos === []) {
            return $categorias->take(3);
        }

        return $categorias
            ->sortByDesc(function (Category $categoria) use ($intentos) {
                $mejor = 0.0;

                foreach ($intentos as $intento) {
                    similar_text(
                        mb_strtolower($categoria->name),
                        mb_strtolower((string) $intento),
                        $porcentaje
                    );
                    $mejor = max($mejor, $porcentaje);
                }

                return $mejor;
            })
            ->take(3)
            ->values();
    }

    private function findCategoryByText(string $input): ?Category
    {
        // Exact match (case-insensitive)
        $cat = Category::whereRaw('LOWER(name) = LOWER(?)', [$input])->first();
        if ($cat) {
            return $cat;
        }

        // Singular y plural: dice "mates" y la categoría guardada es "Mate", o al
        // revés. Sin esto se crean "Mate" y "Mates" como categorías distintas.
        foreach ($this->respuesta->variantes($input) as $variante) {
            $cat = Category::whereRaw('LOWER(name) = LOWER(?)', [$variante])->first();
            if ($cat) {
                return $cat;
            }
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
        // Acertó: la cuenta de intentos fallidos arranca de cero si más adelante
        // vuelve a este paso desde el menú de corrección.
        unset($data['categoria_pending'], $data['categoria_fallos'], $data['categoria_intentos']);

        $this->telegram->sendMessage($chatId, "✅ Categoría: <b>{$category->name}</b>");

        $this->advanceToNextMissingStep($chatId, $conversation, $data);
    }

    /**
     * Determina cuál es el siguiente dato faltante y avanza ahí.
     *
     * Único camino para pasar de un paso al otro. Lo usan tanto el alta escrita
     * como el audio (que llega con varios datos ya cargados), así que lo que la
     * persona ya dijo nunca se vuelve a preguntar.
     */
    private function advanceToNextMissingStep(string $chatId, TelegramConversation $conversation, array $data): void
    {
        // Lo que no se pregunta se completa ACÁ, con el valor por defecto que la
        // dueña ve en Ajustes. Así nunca queda un dato a medio llenar que después
        // alguien complete con una cuenta inventada al momento de guardar.
        $data     = $this->pasos->conDefectos($data);
        $nextStep = $this->pasos->siguiente($data);

        if ($nextStep === 'nuevo:revisar') {
            $this->showConfirm($chatId, $conversation, $data);

            return;
        }

        $conversation->update([
            'step'       => $nextStep,
            'data'       => $data,
            'expires_at' => now()->addMinutes(30),
        ]);

        // Pasos que se contestan escribiendo o dictando.
        $prompt = match ($nextStep) {
            'nuevo:nombre'        => "¿Cuál es el <b>nombre del producto</b>?",
            'nuevo:categoria'     => $this->buildCategoryPrompt(),
            'nuevo:precio_compra' => "¿Cuál es el <b>precio de compra</b>? (número, ej: 25.50)",
            'nuevo:precio_venta'  => "¿Cuál es el <b>precio de venta</b>?",
            'nuevo:cantidad'      => "¿Cuál es la <b>cantidad inicial en stock</b>?",
            default               => '',
        };

        if ($prompt !== '') {
            $this->telegram->sendMessage($chatId, $prompt);
        }

        // Estos pasos se abren con botones en vez de un texto suelto: tocar es
        // más fácil que dictar, sobre todo para elegir de una lista corta.
        match ($nextStep) {
            'nuevo:unidad'       => $this->preguntarUnidad($chatId),
            'nuevo:stock_minimo' => $this->preguntarStockMinimo($chatId, $data),
            'nuevo:foto'         => $this->pedirMasFotos($chatId, count($this->fotosDe($data))),
            default              => null,
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Cómo se vende (unidad)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Pregunta cómo se vende, con la lista numerada y además botones.
     *
     * Acá los botones van de entrada, no al segundo fallo como en la categoría:
     * las unidades son una lista cerrada y corta, y no se pueden crear desde el
     * chat, así que mostrar las que hay es la forma más rápida de acertar.
     */
    private function preguntarUnidad(string $chatId, string $encabezado = ''): void
    {
        $unidades = $this->unidades->disponibles();

        $msg = $encabezado !== '' ? $encabezado . "\n\n" : '';
        $msg .= "¿<b>Cómo se vende</b> este producto?\n\n";

        foreach ($unidades as $idx => $unidad) {
            $msg .= ($idx + 1) . ". {$unidad->name}\n";
        }

        $msg .= "\nTocá una, o decime cómo se vende (por caja, por unidad…).";

        $teclado = TelegramKeyboard::make();

        foreach ($unidades->take(8) as $unidad) {
            $teclado->button($unidad->name, self::BOTON . 'uni:' . $unidad->id);
        }

        $this->telegram->sendMessage($chatId, $msg, 'HTML', $teclado->toArray());
    }

    private function askUnidad(string $chatId, TelegramConversation $conversation, string $input): void
    {
        $data = $conversation->data ?? [];

        $unidad = $this->resolverUnidad($input);

        if ($unidad) {
            $this->setUnidadAndAdvance($chatId, $conversation, $data, $unidad);

            return;
        }

        // No se entendió: preguntar otra vez igual es lo que hace que la persona
        // crea que el bot se colgó. Se repregunta reconociendo el fallo y con los
        // botones, que ya venían desde la primera vez.
        $data['unidad_fallos'] = ($data['unidad_fallos'] ?? 0) + 1;

        $conversation->update(['data' => $data, 'expires_at' => now()->addMinutes(30)]);

        $this->preguntarUnidad(
            $chatId,
            $data['unidad_fallos'] >= 2
                ? "🤔 Seguimos sin entendernos. Mejor elegí tocando."
                : "🤔 No te entendí cómo se vende."
        );
    }

    /**
     * Lo determinista primero; la IA solo si eso falló.
     *
     * El número de la lista se acepta igual que en la categoría, porque la lista
     * va numerada en el mensaje y hay gente que contesta así.
     */
    private function resolverUnidad(string $input): ?Unit
    {
        $limpio = trim($input);

        if (ctype_digit($limpio)) {
            $elegida = $this->unidades->disponibles()->get((int) $limpio - 1);

            if ($elegida) {
                return $elegida;
            }
        }

        if ($unidad = $this->unidades->buscar($limpio)) {
            return $unidad;
        }

        return $this->interpretarUnidadConIa($limpio);
    }

    /**
     * Le pregunta al modelo cuál de las unidades que existen quiso decir.
     *
     * Mismo trato que la categoría: se manda la frase entera (el contexto es lo
     * que hace acertar), el modelo elige de una lista cerrada y lo que conteste
     * se verifica contra la base. Con la IA apagada devuelve null y el paso
     * sigue con sus botones.
     */
    private function interpretarUnidadConIa(string $frase): ?Unit
    {
        $nombres = $this->unidades->disponibles()->pluck('name')->all();

        if ($nombres === [] || $frase === '') {
            return null;
        }

        $elegida = $this->interprete->elegirOpcion($frase, '¿Cómo se vende este producto?', $nombres);

        if ($elegida === null) {
            return null;
        }

        $unidad = Unit::whereRaw('LOWER(name) = LOWER(?)', [$elegida])->first();

        if ($unidad) {
            Log::info('Forma de venta resuelta por la IA', [
                'dijo'     => mb_substr($frase, 0, 60),
                'entendio' => $unidad->name,
            ]);
        }

        return $unidad;
    }

    /** @param array<string, mixed> $data */
    private function setUnidadAndAdvance(string $chatId, TelegramConversation $conversation, array $data, Unit $unidad): void
    {
        $data = $this->guardarUnidad($data, $unidad);

        $this->telegram->sendMessage($chatId, "✅ Se vende por: <b>{$unidad->name}</b>");

        $this->advanceToNextMissingStep($chatId, $conversation, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function guardarUnidad(array $data, Unit $unidad): array
    {
        $data['unidad_id']     = $unidad->id;
        $data['unidad_nombre'] = $unidad->name;
        // Acertó: si más adelante vuelve a este paso desde el menú de corrección,
        // la cuenta de intentos fallidos arranca de cero.
        unset($data['unidad_fallos']);

        return $data;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Avisar cuando queden pocos (stock mínimo)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Pregunta el stock mínimo. Existe porque la dueña lo pidió con esas
     * palabras: "que pida stock mínimo, para evitar que adivine el sistema".
     * Antes se calculaba como el 20% de la cantidad inicial.
     *
     * @param array<string, mixed> $data
     */
    private function preguntarStockMinimo(string $chatId, array $data, string $encabezado = ''): void
    {
        $msg = $encabezado !== '' ? $encabezado . "\n\n" : '';
        $msg .= "🔔 ¿Con <b>cuántas unidades</b> querés que te avise para reponer?\n\n";

        // Al corregir ya hay un valor: mostrarlo evita que lo cambie a ciegas.
        if (isset($data['stock_minimo'])) {
            $msg .= (int) $data['stock_minimo'] === 0
                ? "Ahora está en <b>no avisar</b>.\n\n"
                : "Ahora está en <b>{$data['stock_minimo']}</b>.\n\n";
        }

        $msg .= "Tocá un número, o decime el que quieras (por ejemplo: <i>avisame cuando queden 5</i>).";

        $teclado = TelegramKeyboard::make()->row(
            array_combine(
                array_map(fn (int $n) => (string) $n, self::STOCK_MINIMO_SUGERIDO),
                array_map(fn (int $n) => self::BOTON . 'min:' . $n, self::STOCK_MINIMO_SUGERIDO)
            )
        );
        $teclado->button('No me avises', self::BOTON . 'min:0');

        $this->telegram->sendMessage($chatId, $msg, 'HTML', $teclado->toArray());
    }

    private function askStockMinimo(string $chatId, TelegramConversation $conversation, string $input): void
    {
        $minimo = $this->leerStockMinimo($input);

        if ($minimo === null) {
            $this->preguntarStockMinimo(
                $chatId,
                $conversation->data ?? [],
                "🤔 No entendí con cuántas te aviso."
            );

            return;
        }

        $data = $conversation->data ?? [];
        $data['stock_minimo'] = $minimo;

        $this->telegram->sendMessage(
            $chatId,
            $minimo === 0
                ? "🔔 Listo, de este producto no te aviso."
                : "🔔 Te aviso cuando queden <b>{$minimo}</b>."
        );

        $this->advanceToNextMissingStep($chatId, $conversation, $data);
    }

    /**
     * "avisame cuando queden 5" → 5. "no me avises" → 0.
     *
     * Todo determinista: es un número dicho de cualquier manera, no hace falta
     * gastar una llamada a la IA para sacarlo.
     */
    private function leerStockMinimo(string $input): ?int
    {
        $texto = mb_strtolower(trim($input));

        if ($texto === '') {
            return null;
        }

        // El número manda: "no quiero que me avise antes de 3" tiene un 3.
        $minimo = NumberParser::extractInt($texto);

        if ($minimo !== null) {
            return $minimo < 0 ? null : $minimo;
        }

        // Pedir que no se avise es un cero explícito, no un valor inventado. Se
        // exige que nombre el aviso (o que sea un "no" solo): si no, un "no sé"
        // terminaría configurando "nunca me avises", que no es lo que dijo.
        $noQuiereAviso = preg_match('/\b(no|nunca|sin)\b[^\d]*avis/u', $texto) === 1
            || preg_match('/^(no|nada|ninguno|ninguna|ningun)$/u', $texto) === 1;

        return $noQuiereAviso ? 0 : null;
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

    /**
     * Fotos ya cargadas en esta alta.
     *
     * Una conversación empezada antes de que existieran varias fotos guarda una
     * sola en 'foto_path'; se la trata como lista de un elemento para no dejar a
     * nadie a medio camino tras el despliegue.
     *
     * @return array<int, string>
     */
    private function fotosDe(array $data): array
    {
        if (! empty($data['fotos']) && is_array($data['fotos'])) {
            return array_values($data['fotos']);
        }

        return ! empty($data['foto_path']) ? [$data['foto_path']] : [];
    }

    private function askFoto(string $chatId, TelegramConversation $conversation, array $message): void
    {
        $data  = $conversation->data ?? [];
        $fotos = $this->fotosDe($data);

        // "omitir" se mantiene: hay gente que ya lo tiene aprendido y escribe
        // más rápido de lo que encuentra el botón.
        if (isset($message['text'])) {
            if (in_array(mb_strtolower(trim($message['text'])), ['omitir', 'listo', 'ya', 'no'], true)) {
                $this->terminarFotos($chatId, $conversation, $data);
                return;
            }

            $this->telegram->sendMessage($chatId, "Mandá una foto, o tocá <b>Listo</b> si ya está.");
            $this->pedirMasFotos($chatId, count($fotos));
            return;
        }

        if (! isset($message['photo'])) {
            $this->telegram->sendMessage(
                $chatId,
                "Eso no es una foto. Mandala como imagen (no como archivo), o tocá <b>Listo</b>."
            );
            $this->pedirMasFotos($chatId, count($fotos));
            return;
        }

        if (count($fotos) >= self::MAX_FOTOS) {
            $this->telegram->sendMessage(
                $chatId,
                "Ya cargaste " . self::MAX_FOTOS . " fotos, que es el máximo por producto. Tocá <b>Listo</b> para seguir."
            );
            $this->pedirMasFotos($chatId, count($fotos));
            return;
        }

        try {
            $photo   = end($message['photo']); // la última es la de mejor calidad
            $content = $this->telegram->downloadFile($this->telegram->getFile($photo['file_id']));

            if (empty($content)) {
                throw new \Exception('La descarga vino vacía');
            }

            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_buffer($finfo, $content);
            finfo_close($finfo);

            // El procesamiento posterior (variantes para la tienda) solo entiende
            // imágenes: cualquier otra cosa se rechaza acá y no rompe el alta.
            $extension = match ($mimeType) {
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                default      => null,
            };

            if ($extension === null) {
                $this->telegram->sendMessage($chatId, "Ese archivo no es una imagen que pueda usar. Probá con una foto normal.");
                $this->pedirMasFotos($chatId, count($fotos));
                return;
            }

            Storage::disk('public')->makeDirectory('products');
            $storagePath = 'products/' . Str::uuid() . '.' . $extension;
            Storage::disk('public')->put($storagePath, $content);

            $fotos[] = $storagePath;
            $data['fotos'] = $fotos;
            // Se mantiene para el resto del flujo y para los datos ya guardados.
            $data['foto_path'] = $fotos[0];

            $conversation->update([
                'data'       => $data,
                'expires_at' => now()->addMinutes(30),
            ]);

            $this->telegram->sendMessage($chatId, "📷 Foto " . count($fotos) . " guardada.");
            $this->pedirMasFotos($chatId, count($fotos));
        } catch (\Exception $e) {
            Log::error('Photo download/storage error', ['error' => $e->getMessage()]);
            $this->telegram->sendMessage($chatId, "No pude guardar esa foto. Probá de nuevo, o tocá <b>Listo</b> para seguir sin ella.");
            $this->pedirMasFotos($chatId, count($fotos));
        }
    }

    /** Botones para seguir cargando fotos o dar por terminado el paso. */
    private function pedirMasFotos(string $chatId, int $cargadas): void
    {
        if ($cargadas >= self::MAX_FOTOS) {
            $keyboard = TelegramKeyboard::make()->button('✅ Listo', self::BOTON . 'fotos:listo');
            $this->telegram->sendMessage($chatId, "Llegaste al máximo de fotos.", 'HTML', $keyboard->toArray());
            return;
        }

        $keyboard = TelegramKeyboard::make()->row([
            '📷 Agregar otra' => self::BOTON . 'fotos:otra',
            '✅ Listo'        => self::BOTON . 'fotos:listo',
        ]);

        $texto = $cargadas === 0
            ? "Mandá una <b>foto del producto</b>. Si no tenés, tocá <b>Listo</b>."
            : "¿Querés mandar otra foto del mismo producto? (por ejemplo, otro color)";

        $this->telegram->sendMessage($chatId, $texto, 'HTML', $keyboard->toArray());
    }

    /** Cierra el paso de fotos y sigue el camino de siempre. */
    private function terminarFotos(string $chatId, TelegramConversation $conversation, array $data): void
    {
        $fotos = $this->fotosDe($data);
        $data['fotos'] = $fotos;
        $data['foto_path'] = $fotos[0] ?? null;

        $this->showConfirm($chatId, $conversation, $data);
    }
    private function showConfirm(string $chatId, TelegramConversation $conversation, array $data): void
    {
        // Toda alta se revisa con botones, haya venido dictada o escrita. Empezó
        // siendo solo para las dictadas, porque la transcripción se equivoca; en
        // el uso real la gente también se equivoca escribiendo, y corregir con un
        // botón es mucho más fácil que volver a empezar el alta entera.
        $this->showReview($chatId, $conversation, $data);
    }

    /**
     * Resumen viejo, en texto plano, sin botones. Queda para las conversaciones
     * que ya estaban en el paso 'nuevo:confirmar' cuando se desplegó el cambio.
     */
    private function showConfirmPlano(string $chatId, TelegramConversation $conversation, array $data): void
    {
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
        // Cómo se vende: lo dijo la persona, lo eligió en el paso, o es el valor
        // por defecto configurado. Nunca un id fijo: el id 1 del catálogo que
        // trae el sistema es "Pieza", y así una caja de velas terminaba
        // vendiéndose por pieza y descontando mal el stock desde el primer día.
        $unidadId = $data['unidad_id'] ?? $this->unidades->porDefecto()?->id;

        if (! $unidadId) {
            $this->telegram->sendMessage(
                $chatId,
                "No tenés cargada ninguna forma de venta (unidad, caja, par…).\n\n" .
                "Agregá al menos una desde el sistema y volvemos a intentar este producto."
            );

            return;
        }

        try {
            $productData = ProductData::fromArray([
                'category_id' => $data['categoria_id'],
                'unit_id' => $unidadId,
                'sku' => null, // Auto-generate
                'name' => $data['nombre'],
                'purchase_price' => $data['precio_compra'],
                'selling_price' => $data['precio_venta'],
                'quantity' => $data['cantidad'],
                // Lo que la persona contestó. Si este alta venía de antes de que
                // el paso existiera, el valor por defecto de Ajustes; nunca una
                // cuenta sobre la cantidad inicial, que es lo que había que sacar.
                'min_stock' => (int) ($data['stock_minimo'] ?? ProductIntakeFields::porDefecto('stock_minimo')),
                'is_active' => true,
                'is_public' => (bool) ($data['publicar'] ?? ProductIntakeFields::porDefecto('publicar')),
                'featured' => (bool) ($data['destacado'] ?? false),
                'description' => null,
                'notes' => "Creado vía Telegram",
                'image_path' => $data['foto_path'] ?? null,
            ]);

            $product = $this->productService->createProduct($productData);

            $this->guardarGaleria($product, $this->fotosDe($data));

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
     * Seguir el alta desde afuera. La usa la herramienta del audio: ella guarda
     * lo que entendió del dictado y acá se decide qué falta y cómo se pregunta,
     * así los pasos con botones (y el resumen final) se arman en un solo lado.
     *
     * @param array<string, mixed> $data
     */
    public function continuar(string $chatId, TelegramConversation $conversation, array $data): void
    {
        $this->advanceToNextMissingStep($chatId, $conversation, $data);
    }

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
        $message .= "Se vende por: " . ($data['unidad_nombre'] ?? '—') . "\n";
        $message .= "Precio de compra: " . $this->formatBs($data['precio_compra'] ?? null) . "\n";
        $message .= "Precio de venta: " . $this->formatBs($data['precio_venta'] ?? null) . "\n";
        $message .= "Cantidad: " . ($data['cantidad'] ?? '—') . "\n";
        $message .= "Avisar cuando queden: " . match (true) {
            ! isset($data['stock_minimo'])   => '—',
            (int) $data['stock_minimo'] === 0 => 'no avisar',
            default                          => (string) $data['stock_minimo'],
        } . "\n";
        $cantidadFotos = count($this->fotosDe($data));
        $message .= "Fotos: " . match (true) {
            $cantidadFotos === 0 => 'ninguna',
            $cantidadFotos === 1 => '1',
            default              => $cantidadFotos . ' fotos',
        } . "\n";
        // Solo cuando está destacado: una línea que dice "no" en todas las altas
        // es ruido para alguien que mira el resumen en el celular.
        if (! empty($data['destacado'])) {
            $message .= "⭐ Destacado en la tienda\n";
        }
        $message .= "\n";
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
            // Un dato que el alta no pide no se ofrece corregir: sería prometer
            // algo que después no se usa en ningún lado.
            if ($this->seCorrige($campo)) {
                $keyboard->button($etiqueta, self::BOTON . 'campo:' . $campo);
            }
        }
        // Destacar es un sí/no: se resuelve con el toque, sin pedir texto.
        $keyboard->button(
            empty($data['destacado']) ? '⭐ Destacar en la tienda' : '⭐ Quitar de destacados',
            self::BOTON . 'destacar'
        );
        $keyboard->button('◀️ Volver al resumen', self::BOTON . 'volver');

        $this->telegram->sendMessage(
            $chatId,
            "✏️ <b>¿Qué dato querés corregir?</b>\n\nTocá el que está mal.",
            'HTML',
            $keyboard->toArray()
        );
    }

    /** ¿Este dato se puede corregir hoy? Depende de si el alta lo pide. */
    private function seCorrige(string $campo): bool
    {
        if ($campo === 'unidad') {
            return $this->unidades->hayQueElegir();
        }

        return ProductIntakeFields::pide($campo);
    }

    /** Pide el valor nuevo de un solo campo y deja el paso esperándolo. */
    private function askFieldEdit(string $chatId, TelegramConversation $conversation, array $data, string $campo): void
    {
        if (!isset(self::CAMPOS_EDITABLES[$campo]) || !$this->seCorrige($campo)) {
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

        // Estos tres se piden con botones, no con un texto suelto.
        if (in_array($campo, ['foto', 'unidad', 'stock_minimo'], true)) {
            match ($campo) {
                'foto'         => $this->pedirMasFotos($chatId, count($this->fotosDe($data))),
                'unidad'       => $this->preguntarUnidad($chatId, '✏️ A ver, corrijamos.'),
                'stock_minimo' => $this->preguntarStockMinimo($chatId, $data, '✏️ A ver, corrijamos.'),
            };

            return;
        }

        $prompt = match ($campo) {
            'nombre'        => "✏️ Escribí el <b>nombre</b> correcto del producto.",
            'categoria'     => "✏️ ¿Cuál es la <b>categoría</b> correcta?\n\n" . $this->buildCategoryPrompt(),
            'precio_compra' => "✏️ Escribí el <b>precio de compra</b> correcto (ej: 350).",
            'precio_venta'  => "✏️ Escribí el <b>precio de venta</b> correcto (ej: 450).",
            'cantidad'      => "✏️ Escribí la <b>cantidad</b> correcta (ej: 30).",
            default         => '',
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

            case 'stock_minimo':
                $minimo = $this->leerStockMinimo($text);
                if ($minimo === null) {
                    $this->preguntarStockMinimo($chatId, $data, "🤔 No entendí con cuántas te aviso.");
                    return;
                }
                $data['stock_minimo'] = $minimo;
                break;

            case 'unidad':
                $unidad = $this->resolverUnidad($text);
                if (! $unidad) {
                    $this->preguntarUnidad($chatId, "🤔 No te entendí cómo se vende.");
                    return;
                }
                $data = $this->guardarUnidad($data, $unidad);
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

        // Botones del paso de fotos: ese paso no es "revisión", así que se
        // atienden antes del guard de más abajo.
        if (str_starts_with($action, 'fotos:')) {
            if (! $conversation || $conversation->step !== 'nuevo:foto') {
                $this->telegram->sendMessage($chatId, "Este registro ya no está disponible. Si querés cargar un producto, usá /nuevo.");
                return;
            }

            $datosFoto = $conversation->data ?? [];

            if ($action === 'fotos:listo') {
                $this->terminarFotos($chatId, $conversation, $datosFoto);
                return;
            }

            // 'fotos:otra': solo recordamos qué esperamos; la foto llega como mensaje.
            $this->telegram->sendMessage($chatId, "Mandá la siguiente foto.");
            return;
        }

        // Botones de "cómo se vende" y de "avisar cuando queden". Valen tanto en
        // su paso del alta como cuando se está corrigiendo ese mismo dato desde
        // el resumen, que es otro paso: por eso no alcanza el guard de abajo.
        if (str_starts_with($action, 'uni:') || str_starts_with($action, 'min:')) {
            $esUnidad = str_starts_with($action, 'uni:');
            $campo    = $esUnidad ? 'unidad' : 'stock_minimo';
            $validos  = ['nuevo:' . $campo, self::STEP_EDITAR_CAMPO . $campo];

            if (! $conversation || ! in_array($conversation->step, $validos, true)) {
                $this->telegram->sendMessage($chatId, "Este registro ya no está disponible. Si querés cargar un producto, usá /nuevo.");
                return;
            }

            $datos       = $conversation->data ?? [];
            $corrigiendo = str_starts_with($conversation->step, self::STEP_EDITAR_CAMPO);

            if ($esUnidad) {
                $unidad = Unit::find((int) substr($action, strlen('uni:')));

                if (! $unidad) {
                    $this->telegram->sendMessage($chatId, "Esa forma de venta ya no existe. Elegí otra.");
                    return;
                }

                $datos = $this->guardarUnidad($datos, $unidad);
                $this->telegram->sendMessage($chatId, "✅ Se vende por: <b>{$unidad->name}</b>");
            } else {
                $minimo = (int) substr($action, strlen('min:'));
                $datos['stock_minimo'] = max(0, $minimo);
                $this->telegram->sendMessage(
                    $chatId,
                    $minimo === 0
                        ? "🔔 Listo, de este producto no te aviso."
                        : "🔔 Te aviso cuando queden <b>{$minimo}</b>."
                );
            }

            if ($corrigiendo) {
                $this->showReview($chatId, $conversation, $datos);
            } else {
                $this->advanceToNextMissingStep($chatId, $conversation, $datos);
            }

            return;
        }

        // Botones del paso de categoría: elegir una existente, crear la que se
        // intentó decir, o dejarlo. Viven en 'nuevo:categoria', que no es un paso
        // de revisión, así que se atienden antes del guard de abajo.
        if ($action === 'cancelar' || str_starts_with($action, 'cat:') || $action === 'catnueva') {
            if (! $conversation || $conversation->step !== 'nuevo:categoria') {
                $this->telegram->sendMessage($chatId, "Este registro ya no está disponible. Si querés cargar un producto, usá /nuevo.");
                return;
            }

            $datosCat = $conversation->data ?? [];

            if ($action === 'cancelar') {
                $conversation->delete();
                $this->telegram->sendMessage(
                    $chatId,
                    "Listo, lo dejamos acá. Cuando quieras, escribí <b>crear un producto</b> y lo retomamos."
                );
                return;
            }

            if ($action === 'catnueva') {
                $intentos = $datosCat['categoria_intentos'] ?? [];
                $nombre = $intentos !== [] ? (string) end($intentos) : '';

                if ($nombre === '') {
                    $this->telegram->sendMessage($chatId, "Escribime el nombre de la categoría que querés crear.");
                    return;
                }

                $this->createAndAdvanceCategory($chatId, $conversation, $datosCat, $nombre);
                return;
            }

            $categoria = Category::find((int) substr($action, strlen('cat:')));

            if (! $categoria) {
                $this->telegram->sendMessage($chatId, "Esa categoría ya no existe. Elegí otra.");
                return;
            }

            $this->setCategoryAndAdvance($chatId, $conversation, $datosCat, $categoria);
            return;
        }

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

        // Destacar es un sí/no: el mismo botón prende y apaga, y se vuelve al
        // resumen para que se vea el cambio antes de guardar.
        if ($action === 'destacar') {
            $data['destacado'] = empty($data['destacado']);

            $this->telegram->sendMessage(
                $chatId,
                $data['destacado']
                    ? "⭐ Queda destacado en la tienda."
                    : "Listo, ya no queda destacado."
            );

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

    /**
     * Guarda las fotos como galería del producto, igual que el formulario web:
     * variantes para la tienda y la primera marcada como principal.
     *
     * Una foto que falle al procesarse no tumba el alta: el producto ya existe y
     * perder una imagen es mucho menos grave que perder la carga entera.
     *
     * @param array<int, string> $rutas
     */
    private function guardarGaleria(Product $product, array $rutas): void
    {
        foreach (array_values($rutas) as $indice => $ruta) {
            try {
                $paths = app(ImageProcessor::class)->processExisting($ruta, $product->id);

                ProductImage::create([
                    'product_id' => $product->id,
                    'path'       => $paths['path'],
                    'path_thumb' => $paths['path_thumb'],
                    'path_card'  => $paths['path_card'],
                    'path_full'  => $paths['path_full'],
                    'sort_order' => $indice,
                    'is_primary' => $indice === 0,
                ]);
            } catch (\Throwable $e) {
                Log::warning('No se pudo procesar una foto del bot', [
                    'product_id' => $product->id,
                    'ruta'       => $ruta,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
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
        if (empty($data['unidad_id']) && $this->unidades->hayQueElegir()) { $faltan[] = 'cómo se vende'; }
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

        // "marcalo como destacado" dicho sobre el resumen. Es determinista a
        // propósito: tiene que funcionar también con la IA apagada, que es como
        // arranca una instancia recién instalada.
        if (preg_match('/\b(destacad[oa]s?|destacar|resaltad[oa]|resaltar|favorit[oa])\b/u', $lower)) {
            $this->handleButton($chatId, 'destacar');
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
