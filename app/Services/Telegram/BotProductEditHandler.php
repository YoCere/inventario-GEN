<?php

namespace App\Services\Telegram;

use App\DTOs\ProductData;
use App\Models\Category;
use App\Models\Product;
use App\Models\TelegramConversation;
use App\Models\Unit;
use App\Models\User;
use App\Services\CategoryService;
use App\Services\Messaging\ProductSearchService;
use App\Services\Messaging\TelegramService;
use App\Services\ProductService;
use App\Shop\Models\ProductImage;
use App\Shop\Services\ImageProcessor;
use App\Support\NumberParser;
use App\Support\TelegramKeyboard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Cambiar los datos de un producto que YA existe, desde el chat.
 *
 * Esto no existía. El único botón "Editar" del bot vivía adentro del alta y
 * servía para corregir lo que todavía no se había guardado; sobre un producto
 * viejo contestaba "Este registro ya no está disponible". Y la IA, cuando le
 * pedían cambiar algo, no tenía ninguna herramienta para hacerlo: pedía la
 * categoría, se la daban, y la volvía a pedir. No entendía mal: no tenía manos.
 *
 * Tres decisiones que explican la forma de este archivo:
 *
 *  1. **Vive aparte del alta.** Comparte el estilo (resumen, un botón por dato)
 *     pero no el estado: el alta arma un producto que todavía no existe y se
 *     puede tirar a la basura; acá hay una fila que ya se usa para vender, y un
 *     cambio mal aplicado se lleva puesto el precio con el que se cobra.
 *     Prefijos propios ('editar:*' en los pasos, 'edit:' en los botones) para
 *     que un botón viejo de un flujo no caiga nunca en el otro.
 *
 *  2. **Nada se escribe hasta confirmar.** Los cambios se juntan en la
 *     conversación y se muestran como "Precio de venta: 260 → 300". Recién al
 *     tocar Guardar se tocan el producto y su historial.
 *
 *  3. **Nunca adivina de qué producto se habla.** SKU exacto gana siempre; si
 *     no, se busca por nombre con el buscador que ya usa el resto del bot. Si
 *     hay varios parecidos se eligen tocando, y si no hay ninguno se dice, sin
 *     crear nada.
 */
class BotProductEditHandler
{
    /** Esperando que digan qué producto se edita. */
    private const PASO_BUSCAR = 'editar:buscar';

    /** Menú de "qué dato querés cambiar". */
    private const PASO_MENU = 'editar:menu';

    /** Prefijo de los pasos que esperan el valor nuevo: 'editar:campo:precio_venta'. */
    private const PASO_CAMPO = 'editar:campo:';

    /** Resumen de lo que se va a cambiar, con el botón Guardar. */
    private const PASO_REVISAR = 'editar:revisar';

    /** Esperando fotos para agregar a la galería. */
    private const PASO_FOTO = 'editar:foto';

    /** Prefijo de los botones de este flujo, tal como los rutea BotHandler. */
    private const BOTON = 'edit:';

    /** Permiso que hace falta para cambiar un producto, escribiendo o tocando. */
    private const PERMISO = 'products.manage';

    /** Tope de fotos que se pueden agregar de una sola vez. */
    private const MAX_FOTOS = 5;

    /**
     * Qué se puede cambiar, en el orden en que se muestra. La clave es el campo
     * interno; el valor, el nombre que lee la persona.
     *
     * A diferencia del alta, acá NO se filtra por lo que Ajustes pide preguntar:
     * el producto ya tiene todos estos datos cargados y la dueña pidió poder
     * editarlos todos. Apagar una pregunta del alta no vuelve intocable un dato
     * que ya existe.
     */
    private const CAMPOS_EDITABLES = [
        'nombre'        => 'Nombre',
        'categoria'     => 'Categoría',
        'unidad'        => 'Cómo se vende',
        'precio_compra' => 'Precio de compra',
        'precio_venta'  => 'Precio de venta',
        'cantidad'      => 'Cantidad',
        'stock_minimo'  => 'Avisar cuando queden',
        'destacado'     => 'Destacado en la tienda',
        'publicar'      => 'Mostrar en la tienda',
        'foto'          => 'Fotos',
    ];

    /** Campos que se contestan con un número. */
    private const CAMPOS_NUMERICOS = ['precio_compra', 'precio_venta', 'cantidad', 'stock_minimo'];

    /**
     * Cómo nombra la gente cada dato dentro de la frase, de lo más específico a
     * lo más general: gana la primera que coincide. "precio de venta" tiene que
     * ganarle a "precio", o "el precio de venta del mate" terminaría buscando un
     * producto llamado "venta".
     *
     * @var array<int, array{0: string, 1: array<int, string>}>
     */
    private const FRASES_DE_CAMPO = [
        ['precio_venta',  ['precio', 'de', 'venta']],
        ['precio_compra', ['precio', 'de', 'compra']],
        ['stock_minimo',  ['avisar', 'cuando', 'queden']],
        ['stock_minimo',  ['avisame', 'cuando', 'queden']],
        ['unidad',        ['como', 'se', 'vende']],
        ['unidad',        ['forma', 'de', 'venta']],
        ['unidad',        ['unidad', 'de', 'venta']],
        ['precio_venta',  ['precio', 'venta']],
        ['precio_compra', ['precio', 'compra']],
        ['stock_minimo',  ['stock', 'minimo']],
        ['stock_minimo',  ['aviso', 'de', 'stock']],
        ['categoria',     ['categoria']],
        ['categoria',     ['rubro']],
        ['cantidad',      ['cantidad']],
        ['cantidad',      ['existencias']],
        ['cantidad',      ['existencia']],
        ['cantidad',      ['stock']],
        ['nombre',        ['nombre']],
        ['precio_compra', ['costo']],
        ['unidad',        ['unidad']],
        ['destacado',     ['destacado']],
        ['destacado',     ['destacar']],
        ['publicar',      ['publicar']],
        // Último recurso: en un negocio "el precio" es el que se le cobra al
        // cliente. Se muestra igual como "Precio de venta: 260 → 300", así que
        // si se quiso decir el otro, se ve antes de guardar.
        ['precio_venta',  ['precio']],
    ];

    /**
     * Muletillas propias de pedir una edición. Sin esto, "cambiale el precio al
     * mate camionero" se busca entero y no coincide con ningún producto.
     */
    private const MULETILLAS = [
        'editar', 'edita', 'editame', 'edicion', 'modificar', 'modifica', 'modificame',
        'cambiar', 'cambia', 'cambiale', 'cambiarle', 'cambiame', 'cambio',
        'corregir', 'corrige', 'corregile', 'arreglar', 'arregla', 'arreglale',
        'actualizar', 'actualiza', 'actualizame', 'subir', 'sube', 'subile',
        'bajar', 'baja', 'bajale', 'poner', 'ponele', 'quiero', 'queria', 'necesito',
        'producto', 'productos', 'articulo', 'articulos', 'item',
        'bs', 'bolivianos', 'boliviano', 'pesos',
        'al', 'ese', 'esa', 'aquel', 'tal',
    ];

    /** Avisos de stock que se ofrecen con botones; escribir otro número sigue valiendo. */
    private const STOCK_MINIMO_SUGERIDO = [0, 1, 3, 5, 10];

    public function __construct(
        protected TelegramService $telegram,
        protected ProductService $productService,
        protected CategoryService $categoryService,
        protected ProductSearchService $buscador,
        protected RespuestaNatural $respuesta,
        protected InterpretePaso $interprete,
        protected UnidadDeVenta $unidades,
        protected BotAuthHandler $auth,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    //  Puertas de entrada
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Arranca una edición. `$frase` es lo que la persona dijo además de pedir
     * editar: "el mate camionero", "el precio del P.261010.OPAA a 300", o nada.
     */
    public function start(string $chatId, string $frase = ''): void
    {
        if (! $this->quienEdita($chatId)) {
            return;
        }

        $pedido = $this->leerPedido($frase);

        if ($pedido['producto'] instanceof Product) {
            $this->abrirProducto($chatId, $pedido['producto'], $pedido['campo'], $pedido['valor']);

            return;
        }

        if ($pedido['busqueda'] === '') {
            $this->pedirProducto($chatId, $pedido['campo'], $pedido['valor']);

            return;
        }

        $this->buscarYSeguir($chatId, $pedido['busqueda'], $pedido['campo'], $pedido['valor']);
    }

    /** Mensajes de texto (o voz transcrita) mientras la edición está en curso. */
    public function handle(string $chatId, array $message): void
    {
        if (! $this->quienEdita($chatId)) {
            return;
        }

        $conversation = $this->conversacionViva($chatId);

        if (! $conversation || ! str_starts_with($conversation->step, 'editar:')) {
            $this->avisarQueNoHayEdicion($chatId);

            return;
        }

        $texto = trim((string) ($message['text'] ?? ''));

        if (in_array(mb_strtolower($texto), ['/cancelar', 'cancelar', 'dejalo', 'dejar'], true)) {
            $conversation->delete();
            $this->telegram->sendMessage($chatId, "Listo, no cambié nada.");

            return;
        }

        $paso = $conversation->step;

        if ($paso === self::PASO_FOTO) {
            $this->recibirFoto($chatId, $conversation, $message);

            return;
        }

        if ($paso === self::PASO_BUSCAR) {
            $this->seguirBuscando($chatId, $conversation, $texto);

            return;
        }

        $product = $this->productoDe($conversation);

        if (! $product) {
            $conversation->delete();
            $this->telegram->sendMessage($chatId, "Ese producto ya no está en el sistema. Empezá de nuevo cuando quieras.");

            return;
        }

        if (str_starts_with($paso, self::PASO_CAMPO)) {
            $this->recibirValor($chatId, $conversation, $product, substr($paso, strlen(self::PASO_CAMPO)), $texto);

            return;
        }

        if ($paso === self::PASO_REVISAR) {
            $this->textoEnRevision($chatId, $conversation, $product, $texto);

            return;
        }

        $this->textoEnMenu($chatId, $conversation, $product, $texto);
    }

    /**
     * Toque de un botón de este flujo. `$action` es lo que viene después de
     * 'edit:' (para 'edit:abrir:12' llega 'abrir:12').
     *
     * BotHandler ya valida que quien toca sea el dueño del chat donde se mandó
     * el botón. Acá se valida lo otro: que esa persona pueda editar productos y
     * que el botón caiga sobre una edición de ESTA conversación — un botón
     * copiado de otro chat no encuentra edición abierta y no hace nada.
     */
    public function handleButton(string $chatId, string $action, ?int $messageId = null): void
    {
        $user = $this->quienEdita($chatId);

        if (! $user) {
            return;
        }

        $conversation = $this->conversacionViva($chatId);
        $editando = $conversation && str_starts_with($conversation->step, 'editar:');

        if ($action === 'cancelar') {
            $conversation?->delete();
            $this->telegram->sendMessage($chatId, "Listo, no cambié nada.");

            return;
        }

        if ($action === 'buscar') {
            $this->pedirProducto($chatId, null, null);

            return;
        }

        // "Abrir este" es la PUERTA DE ENTRADA, no un paso interno: llega desde
        // el botón Editar de un resultado de búsqueda, cuando todavía no hay
        // ninguna edición en curso. Exigir una edición abierta acá era
        // exactamente el "no se puede modificar un producto antiguo" que reportó
        // el dueño. La seguridad no se afloja: quien toca ya pasó por el permiso
        // `products.manage` y por la validación de que el botón es de su chat.
        if (! $editando && str_starts_with($action, 'abrir:')) {
            $product = Product::find((int) substr($action, strlen('abrir:')));

            if (! $product) {
                $this->telegram->sendMessage($chatId, "Ese producto ya no está en el sistema. Probá con otro.");

                return;
            }

            $this->abrirProducto($chatId, $product, null, null);

            return;
        }

        if (! $editando) {
            $this->avisarQueNoHayEdicion($chatId);

            return;
        }

        if (str_starts_with($action, 'abrir:')) {
            $this->abrirElegido($chatId, $conversation, (int) substr($action, strlen('abrir:')));

            return;
        }

        $product = $this->productoDe($conversation);

        if (! $product) {
            $conversation->delete();
            $this->telegram->sendMessage($chatId, "Ese producto ya no está en el sistema. Empezá de nuevo cuando quieras.");

            return;
        }

        if ($action === 'guardar') {
            // Se apagan los botones del resumen antes de escribir: un segundo
            // toque sobre el mismo mensaje no puede volver a guardar.
            if ($messageId !== null) {
                $this->telegram->editMessageReplyMarkup($chatId, $messageId, null);
            }

            $this->guardar($chatId, $conversation, $product, $user);

            return;
        }

        if ($action === 'menu') {
            $this->mostrarMenu($chatId, $conversation, $product);

            return;
        }

        if ($action === 'revisar') {
            $this->mostrarRevision($chatId, $conversation, $product);

            return;
        }

        if (str_starts_with($action, 'campo:')) {
            $this->pedirCampo($chatId, $conversation, $product, substr($action, strlen('campo:')));

            return;
        }

        if (str_starts_with($action, 'cat:')) {
            $categoria = Category::find((int) substr($action, strlen('cat:')));

            if (! $categoria) {
                $this->telegram->sendMessage($chatId, "Esa categoría ya no existe. Elegí otra.");

                return;
            }

            $this->anotar($chatId, $conversation, $product, 'categoria', $categoria);

            return;
        }

        if ($action === 'catnueva') {
            $this->crearCategoria($chatId, $conversation, $product);

            return;
        }

        if (str_starts_with($action, 'uni:')) {
            $unidad = Unit::find((int) substr($action, strlen('uni:')));

            if (! $unidad) {
                $this->telegram->sendMessage($chatId, "Esa forma de venta ya no existe. Elegí otra.");

                return;
            }

            $this->anotar($chatId, $conversation, $product, 'unidad', $unidad);

            return;
        }

        if (str_starts_with($action, 'min:')) {
            $this->anotar($chatId, $conversation, $product, 'stock_minimo', max(0, (int) substr($action, strlen('min:'))));

            return;
        }

        if ($action === 'destacar' || $action === 'publicar') {
            $campo = $action === 'destacar' ? 'destacado' : 'publicar';
            $vigente = (bool) $this->valorVigente($product, $this->estado($conversation)['cambios'] ?? [], $campo);

            $this->anotar($chatId, $conversation, $product, $campo, ! $vigente);

            return;
        }

        if (str_starts_with($action, 'fotos:')) {
            if ($action === 'fotos:listo') {
                $this->mostrarRevision($chatId, $conversation, $product);

                return;
            }

            $this->telegram->sendMessage($chatId, "Mandá la siguiente foto.");

            return;
        }

        Log::warning('Botón de edición de producto no reconocido', ['action' => $action]);
        $this->mostrarMenu($chatId, $conversation, $product);
    }

    /**
     * Entrada para la herramienta de la IA: ella ya sabe qué producto y qué
     * datos, pero la resolución del producto y el resumen con botones son los
     * mismos que cuando se escribe. Así hay un solo flujo de edición y no dos.
     *
     * @param  array<string, mixed>  $cambios  campo interno => valor ya normalizado
     * @return array<string, mixed>  qué pasó, para que el modelo lo cuente
     */
    public function iniciarConCambios(string $chatId, string $busqueda, array $cambios): array
    {
        if (! $this->quienEdita($chatId)) {
            return ['status' => 'sin_permiso', 'message' => 'Esta persona no puede editar productos.'];
        }

        $busqueda = trim($busqueda);

        if ($busqueda === '') {
            $this->pedirProducto($chatId, null, null);

            return ['status' => 'falta_producto', 'message' => 'Ya le pregunté al usuario qué producto quiere editar.'];
        }

        $porSku = $this->productoPorSku($busqueda);
        $encontrados = $porSku
            ? collect([$porSku])
            : $this->buscador->searchProducts($busqueda, publicOnly: false);

        if ($encontrados->isEmpty()) {
            $this->telegram->sendMessage(
                $chatId,
                "No encontré ningún producto que se llame \"<b>{$busqueda}</b>\".\n\n" .
                "Probá con otro nombre, o con el código del producto."
            );

            return [
                'status'  => 'no_encontrado',
                'message' => "No existe ningún producto para \"{$busqueda}\". Ya se lo avisé al usuario; "
                    . 'no inventes uno ni lo crees.',
            ];
        }

        if ($encontrados->count() > 1) {
            $this->ofrecerCandidatos($chatId, $encontrados, $this->soloCamposConocidos($cambios));

            return [
                'status'  => 'varios',
                'message' => 'Hay varios productos parecidos. Ya le mandé los botones para que elija; no elijas vos.',
            ];
        }

        $product = $encontrados->first();
        $conversation = $this->empezar($chatId, $product);
        $estado = $this->estado($conversation);

        [$pedidos, $sinResolver] = $this->resolverTextos($this->soloCamposConocidos($cambios));

        $anotados = [];
        $iguales = [];

        foreach ($pedidos as $campo => $valor) {
            if ($this->mismoValor($product, $campo, $valor)) {
                $iguales[] = self::CAMPOS_EDITABLES[$campo];

                continue;
            }

            $estado['cambios'] = $this->conCambio($estado['cambios'] ?? [], $campo, $valor);
            $anotados[] = $campo;
        }

        // Un dato que se dijo pero no corresponde a nada cargado (una categoría
        // que no existe) no se descarta en silencio: se pregunta, con las
        // opciones que sí existen.
        if ($sinResolver !== []) {
            $this->guardarEstado($conversation, $estado, self::PASO_MENU);
            $this->pedirCampo($chatId, $conversation, $product, $sinResolver[0]);

            return [
                'status'   => 'falta_elegir',
                'producto' => $product->name,
                'cambios'  => $anotados,
                'message'  => 'No existe "' . self::CAMPOS_EDITABLES[$sinResolver[0]] . '" con ese valor. '
                    . 'Ya le mandé al usuario las opciones que sí existen para que elija; no inventes una.',
            ];
        }

        if ($anotados === []) {
            $this->guardarEstado($conversation, $estado, self::PASO_MENU);
            $this->mostrarMenu($chatId, $conversation, $product);

            return [
                'status'   => 'sin_cambios',
                'producto' => $product->name,
                'message'  => $iguales === []
                    ? 'Ya le mostré los botones con los datos del producto para que elija qué cambiar.'
                    : 'Esos datos ya estaban así (' . implode(', ', $iguales) . '). Le mostré el menú para que elija otro.',
            ];
        }

        $this->guardarEstado($conversation, $estado, self::PASO_REVISAR);
        $this->mostrarRevision($chatId, $conversation, $product);

        return [
            'status'   => 'por_confirmar',
            'producto' => $product->name,
            'sku'      => $product->sku,
            'cambios'  => $anotados,
            'message'  => 'Ya le mostré el resumen con el botón Guardar. NADA se guardó todavía: no le digas '
                . 'que el cambio está hecho, pedile que confirme tocando Guardar.',
        ];
    }

    /**
     * Lo que llega de afuera puede traer campos que no se editan. Se descartan
     * acá, en un solo lugar, en vez de confiar en quien llama.
     *
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function soloCamposConocidos(array $cambios): array
    {
        return array_filter(
            $cambios,
            fn (mixed $valor, string $campo) => isset(self::CAMPOS_EDITABLES[$campo]) && $valor !== null,
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * La categoría y la unidad pueden llegar como palabras ("mates", "por
     * caja"): se resuelven con lo mismo que usa el camino escrito, contra lo que
     * ya existe. Lo que no corresponde a nada se devuelve aparte para
     * preguntarlo, no para adivinarlo.
     *
     * @param  array<string, mixed>  $cambios
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function resolverTextos(array $cambios): array
    {
        $sinResolver = [];

        if (isset($cambios['categoria']) && ! $cambios['categoria'] instanceof Category) {
            $dicho = (string) $cambios['categoria'];
            $categoria = $this->buscarCategoria($dicho) ?? $this->categoriaConIa($dicho);

            if ($categoria) {
                $cambios['categoria'] = $categoria;
            } else {
                unset($cambios['categoria']);
                $sinResolver[] = 'categoria';
            }
        }

        if (isset($cambios['unidad']) && ! $cambios['unidad'] instanceof Unit) {
            $unidad = $this->resolverUnidad((string) $cambios['unidad']);

            if ($unidad) {
                $cambios['unidad'] = $unidad;
            } else {
                unset($cambios['unidad']);
                $sinResolver[] = 'unidad';
            }
        }

        return [$cambios, $sinResolver];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Seguridad
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Quién está editando, o null si no puede. Avisa en el chat, porque alguien
     * que toca un botón y no recibe nada cree que el bot se colgó.
     */
    private function quienEdita(string $chatId): ?User
    {
        $user = $this->auth->getAuthenticatedUser($chatId);

        if ($user && $user->can(self::PERMISO)) {
            return $user;
        }

        $this->telegram->sendMessage(
            $chatId,
            "No tenés permiso para cambiar los datos de los productos.\n\n" .
            "Pedile a quien administra el sistema que te lo habilite."
        );

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Reconocer el producto
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Lee de la frase el producto (si vino el código), qué dato se quiere
     * cambiar y a qué valor. Todo determinista: tiene que funcionar con la IA
     * apagada, que es como arranca una instancia recién instalada.
     *
     * @return array{producto: ?Product, campo: ?string, valor: mixed, busqueda: string}
     */
    private function leerPedido(string $frase): array
    {
        $palabras = preg_split('/\s+/u', trim($frase), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $palabras = array_values(array_filter(array_map(
            fn (string $p) => trim($p, " \t\n\r\0\x0B.,;:¿?¡!\"'()"),
            $palabras
        ), fn (string $p) => $p !== ''));

        // El código va primero: gana siempre, y además hay que sacarlo antes de
        // buscar números, porque un SKU los trae adentro (P.261010.OPAA).
        [$producto, $palabras] = $this->separarSku($palabras);
        [$campo, $palabras] = $this->separarCampo($palabras);

        $valor = null;

        if ($campo !== null && in_array($campo, self::CAMPOS_NUMERICOS, true)) {
            [$valor, $palabras] = $this->separarNumero($palabras, $campo);
        }

        return [
            'producto' => $producto,
            'campo'    => $campo,
            'valor'    => $valor,
            'busqueda' => $this->respuesta->valor(implode(' ', $palabras), self::MULETILLAS),
        ];
    }

    /**
     * @param  array<int, string>  $palabras
     * @return array{0: ?Product, 1: array<int, string>}
     */
    private function separarSku(array $palabras): array
    {
        foreach ($palabras as $indice => $palabra) {
            if (mb_strlen($palabra) < 3) {
                continue;
            }

            $producto = $this->productoPorSku($palabra);

            if ($producto) {
                unset($palabras[$indice]);

                return [$producto, array_values($palabras)];
            }
        }

        return [null, $palabras];
    }

    /** Código exacto, sin parecidos: o es ese producto, o no es ninguno. */
    private function productoPorSku(string $posibleSku): ?Product
    {
        $posibleSku = trim($posibleSku);

        if ($posibleSku === '') {
            return null;
        }

        return Product::whereRaw('LOWER(sku) = LOWER(?)', [$posibleSku])->first();
    }

    /**
     * @param  array<int, string>  $palabras
     * @return array{0: ?string, 1: array<int, string>}
     */
    private function separarCampo(array $palabras): array
    {
        $normalizadas = array_map([$this, 'normalizar'], $palabras);

        foreach (self::FRASES_DE_CAMPO as [$campo, $frase]) {
            $largo = count($frase);

            for ($i = 0; $i + $largo <= count($normalizadas); $i++) {
                if (array_slice($normalizadas, $i, $largo) !== $frase) {
                    continue;
                }

                array_splice($palabras, $i, $largo);

                return [$campo, array_values($palabras)];
            }
        }

        return [null, $palabras];
    }

    /**
     * El número que se dijo para ese dato. Se busca desde el final porque el
     * valor va después ("el precio del mate 1 litro a 300" → 300, no 1).
     *
     * @param  array<int, string>  $palabras
     * @return array{0: int|null, 1: array<int, string>}
     */
    private function separarNumero(array $palabras, string $campo): array
    {
        $esPrecio = in_array($campo, ['precio_compra', 'precio_venta'], true);

        for ($i = count($palabras) - 1; $i >= 0; $i--) {
            $numero = NumberParser::extractFloat($palabras[$i]);

            if ($numero === null) {
                continue;
            }

            unset($palabras[$i]);
            $palabras = array_values($palabras);

            // Los precios se guardan en centavos, como en el alta.
            $valor = $esPrecio ? (int) round($numero * 100) : (int) round($numero);

            if ($valor < 0 || ($esPrecio && $valor <= 0)) {
                return [null, $palabras];
            }

            return [$valor, $palabras];
        }

        return [null, $palabras];
    }

    /** Busca por nombre y sigue según cuántos aparezcan. */
    private function buscarYSeguir(string $chatId, string $busqueda, ?string $campo, mixed $valor): void
    {
        $encontrados = $this->buscador->searchProducts($busqueda, publicOnly: false);

        if ($encontrados->isEmpty()) {
            $conversation = TelegramConversation::getOrCreate($chatId);
            $this->guardarEstado(
                $conversation,
                ['pendiente' => ['campo' => $campo, 'valor' => $valor]],
                self::PASO_BUSCAR
            );

            $this->telegram->sendMessage(
                $chatId,
                "No encontré ningún producto que se llame \"<b>{$busqueda}</b>\".\n\n" .
                "Decime otro nombre, o el <b>código</b> del producto (ej: P.251010.ABCD).\n" .
                "<i>No creé nada: esto es solo para cambiar productos que ya existen.</i>"
            );

            return;
        }

        if ($encontrados->count() > 1) {
            $this->ofrecerCandidatos(
                $chatId,
                $encontrados,
                ['campo' => $campo, 'valor' => $valor]
            );

            return;
        }

        $this->abrirProducto($chatId, $encontrados->first(), $campo, $valor);
    }

    /** Volvió a escribir qué producto quiere, después de un "no encontré". */
    private function seguirBuscando(string $chatId, TelegramConversation $conversation, string $texto): void
    {
        $pedido = $this->leerPedido($texto);
        $pendiente = $this->estado($conversation)['pendiente'] ?? [];

        // Lo que ya se había pedido antes de saber el producto no se pierde:
        // "cambiale el precio a 300" y recién después el nombre.
        $campo = $pedido['campo'] ?? ($pendiente['campo'] ?? null);
        $valor = $pedido['valor'] ?? ($pendiente['valor'] ?? null);

        if ($pedido['producto'] instanceof Product) {
            $this->abrirProducto($chatId, $pedido['producto'], $campo, $valor);

            return;
        }

        if ($pedido['busqueda'] === '') {
            $this->pedirProducto($chatId, $campo, $valor);

            return;
        }

        $this->buscarYSeguir($chatId, $pedido['busqueda'], $campo, $valor);
    }

    /**
     * Varios parecidos: se elige tocando. Nada se modifica hasta entonces.
     *
     * @param  Collection<int, Product>  $encontrados
     * @param  array<string, mixed>  $pendiente  lo que se quería cambiar, a la espera
     */
    private function ofrecerCandidatos(string $chatId, Collection $encontrados, array $pendiente): void
    {
        $candidatos = $encontrados->take(6);

        $conversation = TelegramConversation::getOrCreate($chatId);
        $this->guardarEstado(
            $conversation,
            [
                'candidatos' => $candidatos->pluck('id')->all(),
                'pendiente'  => $pendiente,
            ],
            self::PASO_BUSCAR
        );

        $teclado = TelegramKeyboard::make();

        foreach ($candidatos as $producto) {
            $teclado->button(
                $producto->name . ' · ' . $this->formatBs($producto->selling_price),
                self::BOTON . 'abrir:' . $producto->id
            );
        }

        $teclado->button('✖️ Dejarlo', self::BOTON . 'cancelar');

        $this->telegram->sendMessage(
            $chatId,
            "Hay varios productos parecidos. ¿Cuál querés cambiar?\n\n" .
            "<i>Todavía no toqué nada.</i>",
            'HTML',
            $teclado->toArray()
        );
    }

    private function pedirProducto(string $chatId, ?string $campo, mixed $valor): void
    {
        $conversation = TelegramConversation::getOrCreate($chatId);
        $this->guardarEstado(
            $conversation,
            ['pendiente' => ['campo' => $campo, 'valor' => $valor]],
            self::PASO_BUSCAR
        );

        $this->telegram->sendMessage(
            $chatId,
            "✏️ <b>¿Qué producto querés cambiar?</b>\n\n" .
            "Decime el <b>nombre</b> o el <b>código</b> (ej: P.251010.ABCD)."
        );
    }

    /** Se eligió uno de los candidatos ofrecidos. */
    private function abrirElegido(string $chatId, TelegramConversation $conversation, int $productoId): void
    {
        $estado = $this->estado($conversation);
        $candidatos = array_map('intval', $estado['candidatos'] ?? []);

        // Si se ofreció una lista, solo vale lo que estaba en ESA lista: un id
        // pegado de otra conversación no abre un producto cualquiera.
        if ($candidatos !== [] && ! in_array($productoId, $candidatos, true)) {
            $this->avisarQueNoHayEdicion($chatId);

            return;
        }

        $product = Product::find($productoId);

        if (! $product) {
            $this->telegram->sendMessage($chatId, "Ese producto ya no está en el sistema. Probá con otro.");

            return;
        }

        $pendiente = $estado['pendiente'] ?? [];

        // Lo pendiente puede venir de dos lados: de la frase escrita
        // (['campo' => ..., 'valor' => ...]) o de la herramienta de la IA, que
        // manda varios datos juntos (['precio_venta' => 30000]).
        if (array_key_exists('campo', $pendiente)) {
            $this->abrirProducto($chatId, $product, $pendiente['campo'], $pendiente['valor'] ?? null);

            return;
        }

        if ($pendiente !== []) {
            $this->iniciarConCambios($chatId, (string) $product->sku, $pendiente);

            return;
        }

        $this->abrirProducto($chatId, $product, null, null);
    }

    /**
     * Producto ya identificado: se anota el cambio pedido, se pregunta el dato
     * que falta, o se muestra el menú.
     */
    private function abrirProducto(string $chatId, Product $product, ?string $campo, mixed $valor): void
    {
        $conversation = $this->empezar($chatId, $product);

        if ($campo === null || ! isset(self::CAMPOS_EDITABLES[$campo])) {
            $this->mostrarMenu($chatId, $conversation, $product);

            return;
        }

        if ($valor !== null) {
            $this->anotar($chatId, $conversation, $product, $campo, $valor);

            return;
        }

        $this->pedirCampo($chatId, $conversation, $product, $campo);
    }

    /** Deja la conversación lista para editar este producto, sin cambios aún. */
    private function empezar(string $chatId, Product $product): TelegramConversation
    {
        $conversation = TelegramConversation::getOrCreate($chatId);
        $estado = $this->estado($conversation);

        // Cambiar de producto en medio de la edición tira los cambios del
        // anterior: aplicarlos sobre otro producto sería un desastre silencioso.
        if ((int) ($estado['producto_id'] ?? 0) !== $product->id) {
            $estado = ['cambios' => []];
        }

        $estado['producto_id'] = $product->id;
        unset($estado['candidatos'], $estado['pendiente']);

        $this->guardarEstado($conversation, $estado, self::PASO_MENU);

        return $conversation;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Menú, pedido de cada dato y resumen
    // ─────────────────────────────────────────────────────────────────────────

    private function mostrarMenu(string $chatId, TelegramConversation $conversation, Product $product): void
    {
        $estado = $this->estado($conversation);
        $cambios = $estado['cambios'] ?? [];

        $this->guardarEstado($conversation, $estado, self::PASO_MENU);

        $mensaje = "✏️ <b>{$product->name}</b>\n";
        $mensaje .= "Código: {$product->sku}\n\n";
        $mensaje .= $this->fichaActual($product);

        if ($cambios !== []) {
            $mensaje .= "\n<b>Sin guardar todavía:</b>\n"
                . implode("\n", $this->lineasDeCambios($product, $cambios)) . "\n";
        }

        $mensaje .= "\n¿Qué dato querés cambiar?";

        $teclado = TelegramKeyboard::make();

        foreach (self::CAMPOS_EDITABLES as $campo => $etiqueta) {
            if (! $this->seEdita($campo, $product)) {
                continue;
            }

            // Destacar y publicar son un sí/no: se resuelven con el toque, sin
            // pedir que alguien escriba "sí".
            if ($campo === 'destacado' || $campo === 'publicar') {
                $prendido = (bool) $this->valorVigente($product, $cambios, $campo);

                $teclado->button(
                    ($campo === 'destacado' ? '⭐ ' : '🛒 ') . ($prendido ? 'Quitar de: ' : 'Poner en: ') . $etiqueta,
                    self::BOTON . ($campo === 'destacado' ? 'destacar' : 'publicar')
                );

                continue;
            }

            $teclado->button($etiqueta, self::BOTON . 'campo:' . $campo);
        }

        if ($cambios !== []) {
            $teclado->button('💾 Guardar cambios', self::BOTON . 'guardar');
        }

        $teclado->button('✖️ Dejarlo', self::BOTON . 'cancelar');

        $this->telegram->sendMessage($chatId, $mensaje, 'HTML', $teclado->toArray());
    }

    /**
     * ¿Este dato se puede cambiar en este producto?
     *
     * Solo dos excepciones, y las dos son porque no habría nada que elegir o el
     * cambio corrompería otra cosa: la forma de venta cuando hay una sola unidad
     * cargada, y la cantidad de un producto repartido en varias ubicaciones
     * (eso se ajusta desde Ubicaciones, no de un tirón desde el chat).
     */
    private function seEdita(string $campo, Product $product): bool
    {
        if ($campo === 'unidad') {
            return Unit::count() > 1;
        }

        if ($campo === 'cantidad') {
            return $product->stocks()->count() <= 1;
        }

        return true;
    }

    private function fichaActual(Product $product): string
    {
        $ficha = "Categoría: " . ($product->category?->name ?? '—') . "\n";
        $ficha .= "Se vende por: " . ($product->unit?->name ?? '—') . "\n";
        $ficha .= "Precio de compra: " . $this->formatBs($product->purchase_price) . "\n";
        $ficha .= "Precio de venta: " . $this->formatBs($product->selling_price) . "\n";
        $ficha .= "Cantidad: {$product->quantity}\n";
        $ficha .= "Avisar cuando queden: " . ((int) $product->min_stock === 0 ? 'no avisar' : $product->min_stock) . "\n";

        if ($product->featured) {
            $ficha .= "⭐ Destacado en la tienda\n";
        }

        return $ficha;
    }

    /** Pide el valor nuevo de un solo dato y deja el paso esperándolo. */
    private function pedirCampo(string $chatId, TelegramConversation $conversation, Product $product, string $campo): void
    {
        if (! isset(self::CAMPOS_EDITABLES[$campo]) || ! $this->seEdita($campo, $product)) {
            if ($campo === 'cantidad') {
                $this->telegram->sendMessage(
                    $chatId,
                    "Este producto tiene stock en varias ubicaciones. La cantidad se ajusta desde " .
                    "<b>Ubicaciones</b>, así no se descuadra entre depósitos."
                );
            }

            $this->mostrarMenu($chatId, $conversation, $product);

            return;
        }

        $estado = $this->estado($conversation);

        if ($campo === 'destacado' || $campo === 'publicar') {
            $this->anotar(
                $chatId,
                $conversation,
                $product,
                $campo,
                ! (bool) $this->valorVigente($product, $estado['cambios'] ?? [], $campo)
            );

            return;
        }

        $this->guardarEstado($conversation, $estado, $campo === 'foto' ? self::PASO_FOTO : self::PASO_CAMPO . $campo);

        match ($campo) {
            'categoria'    => $this->preguntarCategoria($chatId, $product),
            'unidad'       => $this->preguntarUnidad($chatId, $product),
            'stock_minimo' => $this->preguntarStockMinimo($chatId, $product),
            'foto'         => $this->pedirMasFotos($chatId, count($estado['cambios']['fotos'] ?? [])),
            'nombre'       => $this->telegram->sendMessage(
                $chatId,
                "Hoy se llama <b>{$product->name}</b>.\n\n¿Cómo tiene que llamarse?"
            ),
            'precio_compra' => $this->telegram->sendMessage(
                $chatId,
                "El <b>precio de compra</b> hoy es " . $this->formatBs($product->purchase_price) . ".\n\n" .
                "¿Cuánto va a ser? (ej: 350)"
            ),
            'precio_venta' => $this->telegram->sendMessage(
                $chatId,
                "El <b>precio de venta</b> hoy es " . $this->formatBs($product->selling_price) . ".\n\n" .
                "¿Cuánto va a ser? (ej: 450)"
            ),
            'cantidad' => $this->telegram->sendMessage(
                $chatId,
                "Hoy hay <b>{$product->quantity}</b>.\n\n¿Cuántos hay en realidad? (ej: 30)"
            ),
            default => $this->telegram->sendMessage($chatId, "¿Cuál es el valor nuevo?"),
        };
    }

    private function preguntarCategoria(string $chatId, Product $product): void
    {
        $teclado = TelegramKeyboard::make();

        foreach (Category::orderBy('name')->limit(8)->get() as $categoria) {
            if ($categoria->id === $product->category_id) {
                continue; // la que ya tiene no es un cambio
            }

            $teclado->button($categoria->name, self::BOTON . 'cat:' . $categoria->id);
        }

        $teclado->button('◀️ Volver', self::BOTON . 'menu');

        $this->telegram->sendMessage(
            $chatId,
            "Hoy está en <b>" . ($product->category?->name ?? '—') . "</b>.\n\n" .
            "¿En qué categoría va? Tocá una, o escribila.",
            'HTML',
            $teclado->toArray()
        );
    }

    private function preguntarUnidad(string $chatId, Product $product): void
    {
        $teclado = TelegramKeyboard::make();

        foreach ($this->unidades->disponibles() as $unidad) {
            if ($unidad->id === $product->unit_id) {
                continue;
            }

            $teclado->button($unidad->name, self::BOTON . 'uni:' . $unidad->id);
        }

        $teclado->button('◀️ Volver', self::BOTON . 'menu');

        $this->telegram->sendMessage(
            $chatId,
            "Hoy se vende por <b>" . ($product->unit?->name ?? '—') . "</b>.\n\n¿Cómo se vende?",
            'HTML',
            $teclado->toArray()
        );
    }

    private function preguntarStockMinimo(string $chatId, Product $product): void
    {
        $teclado = TelegramKeyboard::make();

        foreach (self::STOCK_MINIMO_SUGERIDO as $cuantas) {
            $teclado->button(
                $cuantas === 0 ? 'No avisarme' : "Cuando queden {$cuantas}",
                self::BOTON . 'min:' . $cuantas
            );
        }

        $teclado->button('◀️ Volver', self::BOTON . 'menu');

        $actual = (int) $product->min_stock === 0 ? 'no avisar' : (string) $product->min_stock;

        $this->telegram->sendMessage(
            $chatId,
            "Hoy el aviso está en <b>{$actual}</b>.\n\n¿Con cuántas te aviso para reponer?",
            'HTML',
            $teclado->toArray()
        );
    }

    /** Llegó texto donde se esperaba el valor de un dato. */
    private function recibirValor(
        string $chatId,
        TelegramConversation $conversation,
        Product $product,
        string $campo,
        string $texto
    ): void {
        switch ($campo) {
            case 'nombre':
                $nombre = $this->respuesta->valor($texto, ['nombre', 'llama', 'llamar', 'llamarse', 'titulo']);

                if (mb_strlen($nombre) < 3) {
                    $this->telegram->sendMessage($chatId, "Ese nombre es muy corto. Escribilo otra vez, con al menos 3 letras.");

                    return;
                }

                $this->anotar($chatId, $conversation, $product, 'nombre', $nombre);

                return;

            case 'precio_compra':
            case 'precio_venta':
                $precio = NumberParser::extractFloat($texto);

                if ($precio === null || $precio <= 0) {
                    $this->telegram->sendMessage($chatId, "No entendí ese precio. Escribí un número (ej: 350).");

                    return;
                }

                $this->anotar($chatId, $conversation, $product, $campo, (int) round($precio * 100));

                return;

            case 'cantidad':
                $cantidad = NumberParser::extractInt($texto);

                if ($cantidad === null || $cantidad < 0) {
                    $this->telegram->sendMessage($chatId, "No entendí esa cantidad. Escribí un número (ej: 30).");

                    return;
                }

                $this->anotar($chatId, $conversation, $product, 'cantidad', $cantidad);

                return;

            case 'stock_minimo':
                if (in_array(mb_strtolower(trim($texto)), ['no', 'no avisar', 'ninguno', 'nada'], true)) {
                    $this->anotar($chatId, $conversation, $product, 'stock_minimo', 0);

                    return;
                }

                $minimo = NumberParser::extractInt($texto);

                if ($minimo === null || $minimo < 0) {
                    $this->telegram->sendMessage($chatId, "No entendí con cuántas te aviso. Decime un número, o tocá una opción.");

                    return;
                }

                $this->anotar($chatId, $conversation, $product, 'stock_minimo', $minimo);

                return;

            case 'unidad':
                $unidad = $this->resolverUnidad($texto);

                if (! $unidad) {
                    $this->telegram->sendMessage($chatId, "No te entendí cómo se vende. Mejor tocá una de las opciones.");
                    $this->preguntarUnidad($chatId, $product);

                    return;
                }

                $this->anotar($chatId, $conversation, $product, 'unidad', $unidad);

                return;

            case 'categoria':
                $this->recibirCategoria($chatId, $conversation, $product, $texto);

                return;

            default:
                $this->mostrarMenu($chatId, $conversation, $product);
        }
    }

    /**
     * Categoría dicha hablando. Lo determinista primero (nombre exacto,
     * singular/plural, parecido); la IA solo si eso no alcanzó, y eligiendo
     * entre las que ya existen. Si igual no se entiende, se ofrece crearla.
     */
    private function recibirCategoria(string $chatId, TelegramConversation $conversation, Product $product, string $texto): void
    {
        $dicho = $this->respuesta->valor($texto, ['categoria', 'rubro']);

        if ($dicho === '') {
            $this->preguntarCategoria($chatId, $product);

            return;
        }

        $categoria = $this->buscarCategoria($dicho) ?? $this->categoriaConIa($dicho);

        if ($categoria) {
            $this->anotar($chatId, $conversation, $product, 'categoria', $categoria);

            return;
        }

        $estado = $this->estado($conversation);
        $estado['categoria_nueva'] = $dicho;
        $this->guardarEstado($conversation, $estado, self::PASO_CAMPO . 'categoria');

        $teclado = TelegramKeyboard::make()
            ->button("➕ Crear «{$dicho}»", self::BOTON . 'catnueva')
            ->button('◀️ Volver', self::BOTON . 'menu');

        $this->telegram->sendMessage(
            $chatId,
            "No encontré la categoría \"<b>{$dicho}</b>\".\n\n¿La creo, o escribís otra?",
            'HTML',
            $teclado->toArray()
        );
    }

    private function crearCategoria(string $chatId, TelegramConversation $conversation, Product $product): void
    {
        $estado = $this->estado($conversation);
        $nombre = trim((string) ($estado['categoria_nueva'] ?? ''));

        if ($nombre === '') {
            $this->telegram->sendMessage($chatId, "Escribime el nombre de la categoría que querés.");

            return;
        }

        try {
            $categoria = $this->categoryService->findOrCreateByName($nombre);
        } catch (\Throwable $e) {
            Log::error('No se pudo crear la categoría desde la edición del bot', ['error' => $e->getMessage()]);
            $this->telegram->sendMessage($chatId, "No pude crear esa categoría. Probá con otro nombre.");

            return;
        }

        $this->telegram->sendMessage($chatId, "✅ Categoría creada: <b>{$categoria->name}</b>");
        $this->anotar($chatId, $conversation, $product, 'categoria', $categoria);
    }

    private function buscarCategoria(string $dicho): ?Category
    {
        $categoria = Category::whereRaw('LOWER(name) = LOWER(?)', [$dicho])->first();

        if ($categoria) {
            return $categoria;
        }

        // "mates" dicho y "Mate" guardada, o al revés.
        foreach ($this->respuesta->variantes($dicho) as $variante) {
            $categoria = Category::whereRaw('LOWER(name) = LOWER(?)', [$variante])->first();

            if ($categoria) {
                return $categoria;
            }
        }

        if (mb_strlen($dicho) < 3) {
            return null;
        }

        return Category::whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($dicho) . '%'])->first();
    }

    private function categoriaConIa(string $dicho): ?Category
    {
        $nombres = Category::orderBy('name')->pluck('name')->all();

        if ($nombres === []) {
            return null;
        }

        $elegida = $this->interprete->elegirOpcion($dicho, '¿En qué categoría va este producto?', $nombres);

        return $elegida === null
            ? null
            : Category::whereRaw('LOWER(name) = LOWER(?)', [$elegida])->first();
    }

    private function resolverUnidad(string $texto): ?Unit
    {
        $unidad = $this->unidades->buscar($texto);

        if ($unidad) {
            return $unidad;
        }

        $nombres = $this->unidades->disponibles()->pluck('name')->all();

        if ($nombres === []) {
            return null;
        }

        $elegida = $this->interprete->elegirOpcion($texto, '¿Cómo se vende este producto?', $nombres);

        return $elegida === null
            ? null
            : Unit::whereRaw('LOWER(name) = LOWER(?)', [$elegida])->first();
    }

    /**
     * Anota un cambio y muestra el resumen. Lo que ya estaba igual no se anota:
     * un "cambio" que no cambia nada ensuciaría el historial del producto.
     */
    private function anotar(
        string $chatId,
        TelegramConversation $conversation,
        Product $product,
        string $campo,
        mixed $valor
    ): void {
        $estado = $this->estado($conversation);
        $estado['producto_id'] = $product->id;
        unset($estado['categoria_nueva']);

        if ($this->mismoValor($product, $campo, $valor)) {
            unset($estado['cambios'][$this->claveDe($campo)]);
            $this->guardarEstado($conversation, $estado, self::PASO_REVISAR);

            $this->telegram->sendMessage(
                $chatId,
                self::CAMPOS_EDITABLES[$campo] . ": ya estaba así. No cambié nada."
            );

            $this->mostrarRevision($chatId, $conversation, $product);

            return;
        }

        $estado['cambios'] = $this->conCambio($estado['cambios'] ?? [], $campo, $valor);
        $this->guardarEstado($conversation, $estado, self::PASO_REVISAR);

        $this->mostrarRevision($chatId, $conversation, $product);
    }

    /**
     * El resumen: qué va a cambiar, y el botón para que pase de verdad. Hasta
     * que no se toque Guardar, el producto sigue igual que antes.
     */
    private function mostrarRevision(string $chatId, TelegramConversation $conversation, Product $product): void
    {
        $estado = $this->estado($conversation);
        $cambios = $estado['cambios'] ?? [];

        if ($cambios === []) {
            $this->mostrarMenu($chatId, $conversation, $product);

            return;
        }

        $this->guardarEstado($conversation, $estado, self::PASO_REVISAR);

        $mensaje = "📋 <b>Esto va a cambiar en {$product->name}</b>\n\n";
        $mensaje .= implode("\n", $this->lineasDeCambios($product, $cambios)) . "\n\n";
        $mensaje .= "Nada se guarda hasta que toques <b>Guardar</b>.";

        $teclado = TelegramKeyboard::make()
            ->row(['💾 Guardar' => self::BOTON . 'guardar', '✏️ Cambiar otro dato' => self::BOTON . 'menu'])
            ->button('✖️ Dejarlo', self::BOTON . 'cancelar');

        $this->telegram->sendMessage($chatId, $mensaje, 'HTML', $teclado->toArray());
    }

    /** Si escribe en vez de tocar, las palabras del resumen también valen. */
    private function textoEnRevision(string $chatId, TelegramConversation $conversation, Product $product, string $texto): void
    {
        $lower = mb_strtolower(trim($texto));

        if (in_array($lower, ['guardar', 'guarda', 'guardalo', 'si', 'sí', 'listo', 'dale', 'ok'], true)) {
            $this->handleButton($chatId, 'guardar');

            return;
        }

        if (in_array($lower, ['editar', 'edita', 'cambiar', 'otro', 'corregir'], true)) {
            $this->mostrarMenu($chatId, $conversation, $product);

            return;
        }

        // Escribió otra orden completa ("y el precio de compra a 200") en vez de
        // tocar: se entiende igual, sobre el mismo producto.
        $pedido = $this->leerPedido($texto);

        if ($pedido['campo'] !== null) {
            $pedido['valor'] !== null
                ? $this->anotar($chatId, $conversation, $product, $pedido['campo'], $pedido['valor'])
                : $this->pedirCampo($chatId, $conversation, $product, $pedido['campo']);

            return;
        }

        $this->telegram->sendMessage($chatId, "Tocá <b>Guardar</b> para dejarlo así, o <b>Cambiar otro dato</b>.");
    }

    private function textoEnMenu(string $chatId, TelegramConversation $conversation, Product $product, string $texto): void
    {
        $lower = mb_strtolower(trim($texto));

        // Escribir el nombre del dato también sirve: es lo natural si viene de
        // dictar y no de tocar botones.
        foreach (self::CAMPOS_EDITABLES as $campo => $etiqueta) {
            if ($lower === mb_strtolower($etiqueta) || $lower === $campo) {
                $this->pedirCampo($chatId, $conversation, $product, $campo);

                return;
            }
        }

        $pedido = $this->leerPedido($texto);

        if ($pedido['campo'] !== null) {
            $pedido['valor'] !== null
                ? $this->anotar($chatId, $conversation, $product, $pedido['campo'], $pedido['valor'])
                : $this->pedirCampo($chatId, $conversation, $product, $pedido['campo']);

            return;
        }

        $this->telegram->sendMessage($chatId, "Tocá el dato que querés cambiar en el mensaje de arriba.");
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Fotos
    // ─────────────────────────────────────────────────────────────────────────

    private function pedirMasFotos(string $chatId, int $cargadas): void
    {
        $teclado = TelegramKeyboard::make();

        if ($cargadas < self::MAX_FOTOS) {
            $teclado->row([
                '📷 Agregar otra' => self::BOTON . 'fotos:otra',
                '✅ Listo'        => self::BOTON . 'fotos:listo',
            ]);
        } else {
            $teclado->button('✅ Listo', self::BOTON . 'fotos:listo');
        }

        $teclado->button('◀️ Volver', self::BOTON . 'menu');

        $texto = $cargadas === 0
            ? "Mandá la <b>foto</b> que querés agregar a este producto."
            : "Van {$cargadas}. ¿Mandás otra?";

        $this->telegram->sendMessage($chatId, $texto, 'HTML', $teclado->toArray());
    }

    /**
     * Guarda la foto en el disco y la anota como cambio pendiente. La galería
     * del producto se toca recién al confirmar.
     */
    private function recibirFoto(string $chatId, TelegramConversation $conversation, array $message): void
    {
        $product = $this->productoDe($conversation);

        if (! $product) {
            $conversation->delete();
            $this->telegram->sendMessage($chatId, "Ese producto ya no está en el sistema.");

            return;
        }

        $estado = $this->estado($conversation);
        $fotos = $estado['cambios']['fotos'] ?? [];

        if (! isset($message['photo'])) {
            if (in_array(mb_strtolower(trim((string) ($message['text'] ?? ''))), ['listo', 'ya', 'omitir', 'no'], true)) {
                $this->mostrarRevision($chatId, $conversation, $product);

                return;
            }

            $this->telegram->sendMessage($chatId, "Mandala como imagen (no como archivo), o tocá <b>Listo</b>.");
            $this->pedirMasFotos($chatId, count($fotos));

            return;
        }

        if (count($fotos) >= self::MAX_FOTOS) {
            $this->telegram->sendMessage($chatId, "Ya van " . self::MAX_FOTOS . " fotos, que es el máximo de una vez.");
            $this->pedirMasFotos($chatId, count($fotos));

            return;
        }

        try {
            $foto = end($message['photo']); // la última es la de mejor calidad
            $contenido = $this->telegram->downloadFile($this->telegram->getFile($foto['file_id']));

            if (empty($contenido)) {
                throw new \RuntimeException('La descarga vino vacía');
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $tipo = finfo_buffer($finfo, $contenido);
            finfo_close($finfo);

            // El procesamiento posterior (variantes para la tienda) solo entiende
            // imágenes: cualquier otra cosa se rechaza acá.
            $extension = match ($tipo) {
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
            $ruta = 'products/' . Str::uuid() . '.' . $extension;
            Storage::disk('public')->put($ruta, $contenido);

            $fotos[] = $ruta;
            $estado['cambios']['fotos'] = $fotos;
            $this->guardarEstado($conversation, $estado, self::PASO_FOTO);

            $this->telegram->sendMessage($chatId, "📷 Foto " . count($fotos) . " lista para agregar.");
            $this->pedirMasFotos($chatId, count($fotos));
        } catch (\Throwable $e) {
            Log::error('No se pudo guardar una foto de la edición', ['error' => $e->getMessage()]);
            $this->telegram->sendMessage($chatId, "No pude guardar esa foto. Probá de nuevo, o tocá <b>Listo</b>.");
            $this->pedirMasFotos($chatId, count($fotos));
        }
    }

    /**
     * Agrega las fotos nuevas a la galería, igual que el formulario web. Si el
     * producto no tenía ninguna, la primera queda como principal.
     *
     * @param array<int, string> $rutas
     */
    private function agregarFotos(Product $product, array $rutas): void
    {
        $yaTenia = ProductImage::where('product_id', $product->id)->count();

        foreach (array_values($rutas) as $indice => $ruta) {
            try {
                $paths = app(ImageProcessor::class)->processExisting($ruta, $product->id);

                ProductImage::create([
                    'product_id' => $product->id,
                    'path'       => $paths['path'],
                    'path_thumb' => $paths['path_thumb'],
                    'path_card'  => $paths['path_card'],
                    'path_full'  => $paths['path_full'],
                    'sort_order' => $yaTenia + $indice,
                    'is_primary' => $yaTenia === 0 && $indice === 0,
                ]);
            } catch (\Throwable $e) {
                // Una foto que falla no tira abajo el resto del cambio: el precio
                // nuevo importa más que la imagen.
                Log::warning('No se pudo procesar una foto de la edición', [
                    'product_id' => $product->id,
                    'ruta'       => $ruta,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Guardar
    // ─────────────────────────────────────────────────────────────────────────

    private function guardar(string $chatId, TelegramConversation $conversation, Product $product, User $user): void
    {
        $cambios = $this->estado($conversation)['cambios'] ?? [];

        if ($cambios === []) {
            $this->telegram->sendMessage($chatId, "Todavía no cambiaste nada.");
            $this->mostrarMenu($chatId, $conversation, $product);

            return;
        }

        // Las líneas se arman ANTES de escribir: después, el valor viejo ya no
        // existe en ningún lado y no se podría mostrar "260 → 300".
        $lineas = $this->lineasDeCambios($product, $cambios);

        try {
            $this->aplicar($product, $cambios, $user);
        } catch (\Throwable $e) {
            Log::error('No se pudo guardar la edición de un producto', [
                'product_id' => $product->id,
                'error'      => $e->getMessage(),
            ]);

            $this->telegram->sendMessage(
                $chatId,
                "No pude guardar el cambio.\n\n" . $this->enCastellano($e) . "\n\nNo se modificó nada."
            );

            $this->mostrarRevision($chatId, $conversation, $product);

            return;
        }

        $conversation->delete();

        $this->telegram->sendMessage(
            $chatId,
            "✅ <b>Listo, lo cambié.</b>\n\n" .
            "{$product->name} ({$product->sku})\n\n" .
            implode("\n", $lineas)
        );
    }

    /**
     * Escribe el cambio con el mismo servicio que usa el formulario web, para
     * que quede en el historial del producto (producto.actualizado, y
     * stock.ajuste_manual cuando se corrige la cantidad) igual que si se hubiera
     * editado desde el sistema.
     *
     * @param array<string, mixed> $cambios
     */
    private function aplicar(Product $product, array $cambios, User $user): void
    {
        $stocks = $product->stocks()->get();

        // La ubicación se conserva: si no se manda, el servicio usa la de por
        // defecto y le movería el stock de depósito a alguien que solo quería
        // corregir un precio.
        $locationId = $stocks->count() === 1 ? $stocks->first()->location_id : null;

        $datos = ProductData::fromArray([
            'category_id'    => $cambios['categoria_id'] ?? $product->category_id,
            'unit_id'        => $cambios['unidad_id'] ?? $product->unit_id,
            'sku'            => $product->sku,
            'sin_code'       => $product->sin_code,
            'name'           => $cambios['nombre'] ?? $product->name,
            'purchase_price' => $cambios['precio_compra'] ?? $product->purchase_price,
            'selling_price'  => $cambios['precio_venta'] ?? $product->selling_price,
            'quantity'       => $cambios['cantidad'] ?? $product->quantity,
            'min_stock'      => $cambios['stock_minimo'] ?? $product->min_stock,
            'is_active'      => $product->is_active,
            'is_public'      => array_key_exists('publicar', $cambios) ? (bool) $cambios['publicar'] : (bool) $product->is_public,
            'featured'       => array_key_exists('destacado', $cambios) ? (bool) $cambios['destacado'] : (bool) $product->featured,
            'description'    => $product->description,
            'notes'          => $product->notes,
            'image_path'     => $product->image_path,
            'location_id'    => $locationId,
        ]);

        $this->comoUsuario($user, fn () => $this->productService->updateProduct($product, $datos));

        if (! empty($cambios['fotos'])) {
            $this->agregarFotos($product, $cambios['fotos']);
        }
    }

    /**
     * El historial de productos guarda quién hizo el cambio, y lo toma de la
     * sesión web. En un webhook de Telegram no hay sesión, así que el cambio
     * quedaría sin autor justo en lo más sensible (los precios). Se presta la
     * identidad de quien habla por el chat solo durante el guardado.
     */
    private function comoUsuario(User $user, callable $accion): mixed
    {
        $guard = auth()->guard();
        $anterior = auth()->user();

        auth()->setUser($user);

        try {
            return $accion();
        } finally {
            if ($anterior) {
                auth()->setUser($anterior);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        }
    }

    /** Lo que el sistema explica en su idioma, dicho como lo diría una persona. */
    private function enCastellano(\Throwable $e): string
    {
        $mensaje = trim($e->getMessage());

        if ($mensaje === '' || preg_match('/SQLSTATE|Exception|stack trace/i', $mensaje)) {
            return 'Hubo un problema al escribir el cambio. Probá de nuevo en un momento.';
        }

        return $mensaje;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Qué cambió
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * "Precio de venta: 260.00 Bs → 300.00 Bs". Que la persona vea lo que hizo,
     * antes y después de guardar.
     *
     * @param  array<string, mixed>  $cambios
     * @return array<int, string>
     */
    private function lineasDeCambios(Product $product, array $cambios): array
    {
        $lineas = [];

        if (isset($cambios['nombre'])) {
            $lineas[] = "Nombre: {$product->name} → <b>{$cambios['nombre']}</b>";
        }

        if (isset($cambios['categoria_id'])) {
            $lineas[] = "Categoría: " . ($product->category?->name ?? '—')
                . " → <b>" . ($cambios['categoria_nombre'] ?? '') . "</b>";
        }

        if (isset($cambios['unidad_id'])) {
            $lineas[] = "Cómo se vende: " . ($product->unit?->name ?? '—')
                . " → <b>" . ($cambios['unidad_nombre'] ?? '') . "</b>";
        }

        if (isset($cambios['precio_compra'])) {
            $lineas[] = "Precio de compra: " . $this->formatBs($product->purchase_price)
                . " → <b>" . $this->formatBs($cambios['precio_compra']) . "</b>";
        }

        if (isset($cambios['precio_venta'])) {
            $lineas[] = "Precio de venta: " . $this->formatBs($product->selling_price)
                . " → <b>" . $this->formatBs($cambios['precio_venta']) . "</b>";
        }

        if (isset($cambios['cantidad'])) {
            $lineas[] = "Cantidad: {$product->quantity} → <b>{$cambios['cantidad']}</b>";
        }

        if (isset($cambios['stock_minimo'])) {
            $lineas[] = "Avisar cuando queden: " . ((int) $product->min_stock === 0 ? 'no avisar' : $product->min_stock)
                . " → <b>" . ((int) $cambios['stock_minimo'] === 0 ? 'no avisar' : $cambios['stock_minimo']) . "</b>";
        }

        if (array_key_exists('destacado', $cambios)) {
            $lineas[] = "Destacado en la tienda: " . ($product->featured ? 'sí' : 'no')
                . " → <b>" . ($cambios['destacado'] ? 'sí' : 'no') . "</b>";
        }

        if (array_key_exists('publicar', $cambios)) {
            $lineas[] = "Mostrar en la tienda: " . ($product->is_public ? 'sí' : 'no')
                . " → <b>" . ($cambios['publicar'] ? 'sí' : 'no') . "</b>";
        }

        if (! empty($cambios['fotos'])) {
            $cuantas = count($cambios['fotos']);
            $lineas[] = "Fotos: se agrega" . ($cuantas === 1 ? ' 1' : "n {$cuantas}");
        }

        return $lineas;
    }

    /**
     * Guarda el cambio con la forma que después entiende el guardado: categoría
     * y unidad viajan con su id y su nombre, para poder mostrar el "antes →
     * después" sin volver a la base.
     *
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function conCambio(array $cambios, string $campo, mixed $valor): array
    {
        if ($campo === 'categoria') {
            $categoria = $valor instanceof Category ? $valor : Category::find((int) $valor);

            if ($categoria) {
                $cambios['categoria_id'] = $categoria->id;
                $cambios['categoria_nombre'] = $categoria->name;
            }

            return $cambios;
        }

        if ($campo === 'unidad') {
            $unidad = $valor instanceof Unit ? $valor : Unit::find((int) $valor);

            if ($unidad) {
                $cambios['unidad_id'] = $unidad->id;
                $cambios['unidad_nombre'] = $unidad->name;
            }

            return $cambios;
        }

        if ($campo === 'foto') {
            $cambios['fotos'] = is_array($valor) ? $valor : [$valor];

            return $cambios;
        }

        $cambios[$campo] = $valor;

        return $cambios;
    }

    /** Dónde se guarda cada campo dentro de los cambios pendientes. */
    private function claveDe(string $campo): string
    {
        return match ($campo) {
            'categoria' => 'categoria_id',
            'unidad'    => 'unidad_id',
            'foto'      => 'fotos',
            default     => $campo,
        };
    }

    /** ¿El valor nuevo es el que el producto ya tiene? */
    private function mismoValor(Product $product, string $campo, mixed $valor): bool
    {
        return match ($campo) {
            'nombre'        => trim((string) $valor) === (string) $product->name,
            'categoria'     => $this->idDe($valor) === (int) $product->category_id,
            'unidad'        => $this->idDe($valor) === (int) $product->unit_id,
            'precio_compra' => (int) $valor === (int) $product->purchase_price,
            'precio_venta'  => (int) $valor === (int) $product->selling_price,
            'cantidad'      => (int) $valor === (int) $product->quantity,
            'stock_minimo'  => (int) $valor === (int) $product->min_stock,
            'destacado'     => (bool) $valor === (bool) $product->featured,
            'publicar'      => (bool) $valor === (bool) $product->is_public,
            default         => false,
        };
    }

    /** El id, venga el modelo entero o solo el número. */
    private function idDe(mixed $valor): int
    {
        if ($valor instanceof Category || $valor instanceof Unit) {
            return (int) $valor->id;
        }

        return (int) $valor;
    }

    /**
     * El valor que tendría hoy el producto si se guardara lo pendiente.
     *
     * @param array<string, mixed> $cambios
     */
    private function valorVigente(Product $product, array $cambios, string $campo): mixed
    {
        if (array_key_exists($campo, $cambios)) {
            return $cambios[$campo];
        }

        return match ($campo) {
            'destacado' => (bool) $product->featured,
            'publicar'  => (bool) $product->is_public,
            default     => null,
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Estado de la conversación
    // ─────────────────────────────────────────────────────────────────────────

    private function conversacionViva(string $chatId): ?TelegramConversation
    {
        return TelegramConversation::where('chat_id', $chatId)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    /** @return array<string, mixed> */
    private function estado(TelegramConversation $conversation): array
    {
        $data = is_array($conversation->data) ? $conversation->data : [];

        return is_array($data['editar'] ?? null) ? $data['editar'] : [];
    }

    /** @param array<string, mixed> $estado */
    private function guardarEstado(TelegramConversation $conversation, array $estado, string $paso): void
    {
        $data = is_array($conversation->data) ? $conversation->data : [];

        // Se conserva la memoria del asistente (vive en la misma fila) y se
        // descarta cualquier alta a medio hacer: editar y dar de alta a la vez
        // mezclaría dos productos distintos.
        $data = array_filter(
            $data,
            fn (string $clave) => $clave === 'agent_history',
            ARRAY_FILTER_USE_KEY
        );

        $data['editar'] = $estado;

        $conversation->update([
            'step'       => $paso,
            'data'       => $data,
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    private function productoDe(TelegramConversation $conversation): ?Product
    {
        $id = (int) ($this->estado($conversation)['producto_id'] ?? 0);

        return $id > 0 ? Product::find($id) : null;
    }

    private function avisarQueNoHayEdicion(string $chatId): void
    {
        $this->telegram->sendMessage(
            $chatId,
            "Esa edición ya no está abierta.\n\n" .
            "Si querés cambiar algo de un producto, escribime <b>editar producto</b> y seguimos."
        );
    }

    private function formatBs(?int $centavos): string
    {
        if ($centavos === null) {
            return '—';
        }

        return number_format($centavos / 100, 2) . ' Bs';
    }

    private function normalizar(string $palabra): string
    {
        $palabra = mb_strtolower(trim($palabra));

        $transliterado = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $palabra);
        if ($transliterado !== false && $transliterado !== '') {
            $palabra = preg_replace("/['\"`^~](?=[a-z])/", '', $transliterado) ?? $palabra;
        }

        return preg_replace('/[^a-z0-9]/', '', $palabra) ?? $palabra;
    }
}
