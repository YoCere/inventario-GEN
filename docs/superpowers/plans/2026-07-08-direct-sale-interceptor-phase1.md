# Interceptor de venta directo — Plan (SP1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ejecutar una orden de venta hablada/escrita clara ("vende 3 figuras de mario a 10", o tras foto "vende 3 del segundo a 30") al instante, de forma determinista y sin LLM.

**Architecture:** Un parser puro `SaleCommandParser` extrae cantidad + precio + producto (por nombre o por posición). `BotSaleHandler::tryQuickSell()` orquesta: resuelve el producto (búsqueda fuzzy o posición en la lista de candidatos pendiente) y vende con el `QuickSaleService` ya existente. Se engancha en `BotHandler` antes del ruteo al agente IA, en texto y en voz; si no calza, cae al agente (sin cambios).

**Tech Stack:** Laravel 11, PHP 8.x, PHPUnit, `NumberParser`, `ProductSearchService`, `QuickSaleService`, `TelegramConversation`.

**Alcance SP1:** parser + venta directa por nombre + venta posicional (sobre lista de foto/desambiguación). NO incluye botones inline (SP2, spec aparte).

**Regla MySQL:** nunca `migrate:fresh`. Sin migraciones nuevas.

---

## Estructura de archivos

| Archivo | Responsabilidad |
|---------|-----------------|
| `app/DTOs/ParsedSaleCommand.php` | DTO readonly del comando parseado |
| `app/Services/Sales/SaleCommandParser.php` | Parser puro texto → ParsedSaleCommand\|null |
| `app/Services/Telegram/BotSaleHandler.php` | `tryQuickSell()` + `handleDirectPick()` (modificar) |
| `app/Services/Telegram/BotHandler.php` | Enganche en ruteo texto + voz + step (modificar) |
| `tests/Feature/Sales/SaleCommandParserTest.php` | Unit del parser |
| `tests/Feature/Sales/DirectSaleTest.php` | Feature del handler (nombre + posicional) |

**Seeders contables en tests que venden** (createSale los exige): en `setUp()`
```php
$this->seed([
    \Database\Seeders\AccountingPeriodSeeder::class,
    \Database\Seeders\ChartOfAccountSeeder::class,
    \Database\Seeders\SettingSeeder::class,
]);
```
Confirmar nombres exactos en `tests/Feature/Sales/SaleServiceTest.php`.

---

## Task 1: SaleCommandParser (parser puro)

**Files:**
- Create: `app/DTOs/ParsedSaleCommand.php`
- Create: `app/Services/Sales/SaleCommandParser.php`
- Test: `tests/Feature/Sales/SaleCommandParserTest.php`

- [ ] **Step 1: Write the DTO**

`app/DTOs/ParsedSaleCommand.php`:

```php
<?php

namespace App\DTOs;

use App\Enums\PaymentMethod;

/**
 * Resultado del parseo determinista de una orden de venta.
 * Exactamente uno de $productQuery (por nombre) o $position (posicional) es no-nulo.
 */
final class ParsedSaleCommand
{
    public function __construct(
        public readonly int $quantity,
        public readonly ?int $unitPriceCents,
        public readonly ?int $totalPriceCents,
        public readonly PaymentMethod $method,
        public readonly ?string $productQuery,
        public readonly ?int $position,
    ) {}
}
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/Sales/SaleCommandParserTest.php`:

```php
<?php

namespace Tests\Feature\Sales;

use App\Enums\PaymentMethod;
use App\Services\Sales\SaleCommandParser;
use PHPUnit\Framework\TestCase;

class SaleCommandParserTest extends TestCase
{
    private SaleCommandParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new SaleCommandParser();
    }

    public function test_name_with_qty_and_unit_price(): void
    {
        $c = $this->parser->parse('vende 3 figuras de mario a 10');
        $this->assertNotNull($c);
        $this->assertSame(3, $c->quantity);
        $this->assertSame(1000, $c->unitPriceCents);
        $this->assertNull($c->totalPriceCents);
        $this->assertSame('figuras de mario', $c->productQuery);
        $this->assertNull($c->position);
        $this->assertSame(PaymentMethod::CASH, $c->method);
    }

    public function test_name_without_price(): void
    {
        $c = $this->parser->parse('véndeme dos fundas');
        $this->assertNotNull($c);
        $this->assertSame(2, $c->quantity);
        $this->assertNull($c->unitPriceCents);
        $this->assertSame('fundas', $c->productQuery);
    }

    public function test_name_with_total_price(): void
    {
        $c = $this->parser->parse('vende 5 cables en total 40');
        $this->assertSame(5, $c->quantity);
        $this->assertSame(4000, $c->totalPriceCents);
        $this->assertNull($c->unitPriceCents);
        $this->assertSame('cables', $c->productQuery);
    }

    public function test_word_numbers(): void
    {
        $c = $this->parser->parse('vende tres labubus a diez');
        $this->assertSame(3, $c->quantity);
        $this->assertSame(1000, $c->unitPriceCents);
        $this->assertSame('labubus', $c->productQuery);
    }

    public function test_transfer_method(): void
    {
        $c = $this->parser->parse('vende 2 fundas a 20 por transferencia');
        $this->assertSame(PaymentMethod::TRANSFER, $c->method);
        $this->assertSame(2, $c->quantity);
        $this->assertSame(2000, $c->unitPriceCents);
        $this->assertSame('fundas', $c->productQuery);
    }

    public function test_positional_with_qty_and_price(): void
    {
        $c = $this->parser->parse('vende 3 del segundo a 30');
        $this->assertSame(3, $c->quantity);
        $this->assertSame(2, $c->position);
        $this->assertSame(3000, $c->unitPriceCents);
        $this->assertNull($c->productQuery);
    }

    public function test_positional_el_primero(): void
    {
        $c = $this->parser->parse('vende el primero');
        $this->assertSame(1, $c->quantity);
        $this->assertSame(1, $c->position);
        $this->assertNull($c->productQuery);
    }

    public function test_positional_numero(): void
    {
        $c = $this->parser->parse('vende 2 del número 3 a 15');
        $this->assertSame(2, $c->quantity);
        $this->assertSame(3, $c->position);
        $this->assertSame(1500, $c->unitPriceCents);
    }

    public function test_past_tense_is_not_a_command(): void
    {
        $this->assertNull($this->parser->parse('vendí 3 hoy'));
        $this->assertNull($this->parser->parse('cuánto vendí ayer'));
    }

    public function test_non_command(): void
    {
        $this->assertNull($this->parser->parse('hola'));
        $this->assertNull($this->parser->parse('¿tienes fundas?'));
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test tests/Feature/Sales/SaleCommandParserTest.php`
Expected: FAIL — class `App\Services\Sales\SaleCommandParser` not found.

- [ ] **Step 4: Write the parser**

`app/Services/Sales/SaleCommandParser.php`:

```php
<?php

namespace App\Services\Sales;

use App\DTOs\ParsedSaleCommand;
use App\Enums\PaymentMethod;
use App\Support\NumberParser;

/**
 * Parser determinista de órdenes de venta habladas/escritas. Sin dependencias de DB.
 *
 * Gramática soportada (el precio va al final):
 *   [vende|véndeme|véndele|véndenos|vender|vendan]
 *     ( [cant?] <nombre producto> | [cant?] (del|el|número) <ordinal|N> )
 *     ( a|por|cada [uno] <precio> [bs] | en total <precio> )?
 *     [por transferencia]?
 */
class SaleCommandParser
{
    // Solo imperativo/infinitivo. El pasado (vendí/vendió/vendiste/vendimos) NO matchea → reportes.
    private const SELL_VERB = '/^\s*(?:v[eé]nde(?:me|le|nos)?|vender|vendan)\b/u';

    private const ORDINALS = [
        'primero' => 1, 'primera' => 1, 'segundo' => 2, 'segunda' => 2,
        'tercero' => 3, 'tercera' => 3, 'cuarto' => 4, 'cuarta' => 4,
        'quinto' => 5, 'quinta' => 5, 'sexto' => 6, 'séptimo' => 7, 'septimo' => 7,
        'octavo' => 8, 'noveno' => 9, 'décimo' => 10, 'decimo' => 10,
    ];

    // Palabras a descartar del nombre del producto. OJO: NO incluir "de" — es parte de
    // nombres reales ("figura de mario"); el buscador fuzzy la tolera igual.
    private const NOISE = ['unidad', 'unidades', 'pieza', 'piezas', 'uni', 'pza', 'pzas'];

    public function parse(string $text): ?ParsedSaleCommand
    {
        $t = mb_strtolower(trim($text));
        if ($t === '' || ! preg_match(self::SELL_VERB, $t)) {
            return null;
        }

        // 1. Quitar verbo.
        $t = trim((string) preg_replace(self::SELL_VERB, '', $t, 1));

        // 2. Método de pago.
        $method = PaymentMethod::CASH;
        if (preg_match('/\btransfer(?:encia)?\b/u', $t)) {
            $method = PaymentMethod::TRANSFER;
            $t = trim((string) preg_replace('/\b(?:por\s+)?transfer(?:encia)?\b/u', '', $t));
        }

        // 3. Precio (siempre al final). "en total N" = total; "a|por|cada [uno] N" = por unidad.
        [$t, $unitPriceCents, $totalPriceCents] = $this->extractPrice($t);

        // 4. Cantidad + destino (posición o nombre) del texto restante.
        [$quantity, $productQuery, $position] = $this->extractQtyAndTarget($t);

        if ($productQuery === null && $position === null) {
            return null; // No hay ni nombre ni posición → no es una orden vendible.
        }

        return new ParsedSaleCommand(
            quantity: $quantity,
            unitPriceCents: $unitPriceCents,
            totalPriceCents: $totalPriceCents,
            method: $method,
            productQuery: $productQuery,
            position: $position,
        );
    }

    /** @return array{0:string,1:?int,2:?int} [restante, unitCents, totalCents] */
    private function extractPrice(string $t): array
    {
        // Total explícito.
        if (preg_match('/^(.*)\ben\s+total\b(.*)$/u', $t, $m)) {
            $price = NumberParser::extractFloat($m[2]);
            if ($price !== null) {
                return [trim($m[1]), null, (int) round($price * 100)];
            }
        }
        // Por unidad: preposición + número (dígito o palabra) al final.
        if (preg_match('/^(.*)\b(?:a|por|cada(?:\s+un[oa])?)\s+(?:bs\.?\s*)?([\p{L}\d][\p{L}\d.,]*)\s*(?:bs|bolivianos)?\s*$/u', $t, $m)) {
            $price = NumberParser::extractFloat($m[2]);
            if ($price !== null) {
                return [trim($m[1]), (int) round($price * 100), null];
            }
        }
        return [$t, null, null];
    }

    /** @return array{0:int,1:?string,2:?int} [cantidad, productQuery|null, position|null] */
    private function extractQtyAndTarget(string $head): array
    {
        $head = trim($head);
        $ordinalAlt = implode('|', array_keys(self::ORDINALS));

        // 1. Cantidad = SOLO el número inicial (inmediato tras el verbo). Un número dentro
        //    del nombre ("iphone 12", "cargador 20w") NO es cantidad.
        $quantity = 1;
        if (preg_match('/^(\d+)\b\s*/u', $head, $m)) {
            $quantity = max(1, (int) $m[1]);
            $head = trim((string) preg_replace('/^\d+\b\s*/u', '', $head, 1));
        } elseif (preg_match('/^(\p{L}+)\s*/u', $head, $m) && in_array($m[1], NumberParser::spanishWords(), true)) {
            $quantity = max(1, (int) NumberParser::extractInt($m[1]));
            $head = trim((string) preg_replace('/^\p{L}+\s*/u', '', $head, 1));
        }

        // 2. Posición: requiere "del" literal / "número N" / "el <ord>$". NUNCA bare "de"
        //    (es parte de nombres reales: "cable de 3 metros").
        if (preg_match('/\bdel\s+(?:n[uú]mero\s+|#\s*)?(' . $ordinalAlt . '|\d+)\b/u', $head, $m)
            || preg_match('/\bn[uú]mero\s+(\d+)\b/u', $head, $m)
            || preg_match('/^(?:el|la)\s+(' . $ordinalAlt . '|\d+)$/u', $head, $m)) {
            $token = $m[1];
            $position = ctype_digit($token) ? (int) $token : (self::ORDINALS[$token] ?? null);
            if ($position !== null) {
                return [$quantity, null, $position];
            }
        }

        // 3. Nombre = resto sin palabras ruido. NO se quitan dígitos embebidos (modelos/specs).
        $name = $head;
        foreach (self::NOISE as $noise) {
            $name = (string) preg_replace('/\b' . preg_quote($noise, '/') . '\b/u', '', $name);
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return [$quantity, $name === '' ? null : $name, null];
    }
}
```

- [ ] **Step 5: Expose the Spanish word list from NumberParser**

`SaleCommandParser` uses `NumberParser::spanishWords()`. Add it to `app/Support/NumberParser.php` (the private const `SPANISH_WORDS` already exists):

```php
    /** @return array<int, string> Palabras-número reconocidas (para parsers externos). */
    public static function spanishWords(): array
    {
        return array_keys(self::SPANISH_WORDS);
    }
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Sales/SaleCommandParserTest.php`
Expected: PASS (10 tests). If a regex misbehaves on a case, adjust the regex to satisfy the exact test inputs — do NOT change the expected values. If genuinely stuck after 2 attempts on one case, report DONE_WITH_CONCERNS with the failing input/output.

- [ ] **Step 7: Commit**

```bash
git add app/DTOs/ParsedSaleCommand.php app/Services/Sales/SaleCommandParser.php app/Support/NumberParser.php tests/Feature/Sales/SaleCommandParserTest.php
git commit -m "feat(sales): SaleCommandParser (orden de venta determinista, nombre + posicional)"
```

---

## Task 2: BotSaleHandler::tryQuickSell (modo nombre) + wiring

**Files:**
- Modify: `app/Services/Telegram/BotSaleHandler.php`
- Modify: `app/Services/Telegram/BotHandler.php`
- Test: `tests/Feature/Sales/DirectSaleTest.php`

Contexto: `BotSaleHandler` ya recibe `TelegramService $telegram` (y otras deps) en su constructor y usa `TelegramConversation`. Necesita además `SaleCommandParser`, `ProductSearchService` y `QuickSaleService` — se resuelven vía `app()` dentro del método para no tocar el constructor (patrón ya usado para servicios puntuales), o se inyectan si el constructor lo permite fácilmente. Usar `app()` aquí.

- [ ] **Step 1: Add tryQuickSell + helpers to BotSaleHandler**

Agregar a `app/Services/Telegram/BotSaleHandler.php`:

```php
    /**
     * Interceptor de venta directa. Devuelve true si manejó el mensaje (era una orden
     * de venta), false si no lo era (el caller sigue con el ruteo normal).
     */
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

        // Modo posicional se resuelve en Task 3. Aquí solo modo nombre.
        if ($cmd->position !== null) {
            return $this->sellByPosition($chatId, $cmd, $user); // definido en Task 3
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

    /** Guarda estado pendiente y muestra lista numerada para elegir. */
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

    /** El usuario respondió el número tras una desambiguación por nombre. */
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

    /** Ejecuta la venta con QuickSaleService y responde el desglose. */
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
```

- [ ] **Step 2: Write the failing feature test (name mode)**

`tests/Feature/Sales/DirectSaleTest.php`:

```php
<?php

namespace Tests\Feature\Sales;

use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\TelegramConversation;
use App\Models\TelegramUser;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Messaging\TelegramService;
use App\Services\Telegram\BotSaleHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DirectSaleTest extends TestCase
{
    use RefreshDatabase;

    private BotSaleHandler $handler;
    private Location $location;
    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            \Database\Seeders\AccountingPeriodSeeder::class,
            \Database\Seeders\ChartOfAccountSeeder::class,
            \Database\Seeders\SettingSeeder::class,
        ]);

        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->andReturn([]);
        $this->app->instance(TelegramService::class, $telegram);

        $this->handler = app(BotSaleHandler::class);

        $this->seller = User::factory()->staff()->create();
        TelegramUser::create(['chat_id' => '555', 'user_id' => $this->seller->id, 'identifier' => 'alice']);

        $warehouse = Warehouse::create(['name' => 'Almacén', 'code' => 'ALM']);
        $this->location = Location::create(['name' => 'Estante', 'code' => 'EST', 'warehouse_id' => $warehouse->id]);
    }

    private function stocked(string $name, int $selling, int $purchase, int $stock): Product
    {
        $p = Product::factory()->create([
            'name' => $name, 'selling_price' => $selling, 'purchase_price' => $purchase, 'quantity' => 0, 'is_active' => true,
        ]);
        ProductStock::create(['product_id' => $p->id, 'location_id' => $this->location->id, 'quantity' => $stock]);
        $p->update(['quantity' => $stock]);
        return $p;
    }

    public function test_non_sale_message_returns_false(): void
    {
        $this->assertFalse($this->handler->tryQuickSell('555', 'hola'));
    }

    public function test_direct_sale_by_name_single_match(): void
    {
        $p = $this->stocked('Figura Mario', 2000, 1000, 10);

        $handled = $this->handler->tryQuickSell('555', 'vende 3 figura mario a 10');

        $this->assertTrue($handled);
        $sale = Sale::first();
        $this->assertNotNull($sale);
        $this->assertSame(3000, $sale->total); // 3 × 1000
        $this->assertSame(7, $p->fresh()->quantity);
    }

    public function test_ambiguous_by_name_sets_pending_and_number_completes(): void
    {
        $this->stocked('Cargador Samsung 25w', 5000, 3000, 5);
        $this->stocked('Cargador Samsung 45w', 8000, 5000, 5);

        $handled = $this->handler->tryQuickSell('555', 'vende 2 cargador samsung a 40');
        $this->assertTrue($handled);
        $this->assertSame(0, Sale::count()); // aún no vende, espera elección

        $conv = TelegramConversation::where('chat_id', '555')->first();
        $this->assertSame('venta_directa:elegir', $conv->step);

        // Responde el número → vende el elegido.
        $this->handler->handleDirectPick('555', $conv, '2');
        $sale = Sale::first();
        $this->assertNotNull($sale);
        $this->assertSame(8000, $sale->total); // 2 × 4000
    }

    public function test_not_found_message_no_sale(): void
    {
        $handled = $this->handler->tryQuickSell('555', 'vende 1 productoinexistentexyz');
        $this->assertTrue($handled);
        $this->assertSame(0, Sale::count());
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test tests/Feature/Sales/DirectSaleTest.php`
Expected: FAIL — `sellByPosition` not defined yet (Task 3) will cause a fatal only if a positional test runs; the name-mode tests fail because `tryQuickSell` doesn't exist. Implement Step 1 first; if PHP complains about the missing `sellByPosition` referenced in `tryQuickSell`, add a temporary stub `private function sellByPosition($chatId,$cmd,$user): bool { return true; }` that Task 3 replaces.

- [ ] **Step 4: Wire into BotHandler**

En `app/Services/Telegram/BotHandler.php`:

**A — routing de texto libre.** En `dispatch()`, en el bloque "Free text routing" (antes de `if (strtolower($text) === 'vender')`), agregar:
```php
            // Interceptor de venta directa (determinista) antes del agente IA.
            if ($this->saleHandler->tryQuickSell($chatId, $text)) {
                return;
            }
```

**B — routing de voz.** En `handleVoiceMessage()`, tras obtener `$transcript` y ANTES del ruteo a agente/búsqueda (donde hoy hace `isConversationalQuery($transcript)`), agregar:
```php
            if ($this->saleHandler->tryQuickSell($chatId, $transcript)) {
                return;
            }
```

**C — step de elección.** En el bloque `if ($conversation)` de `dispatch()`, agregar una rama:
```php
                } elseif ($conversation->step === 'venta_directa:elegir') {
                    $this->saleHandler->handleDirectPick($chatId, $conversation, trim($text));
                    return;
```

**D — /cancelar activo.** Agregar `$conversation->step === 'venta_directa:elegir'` a la lista `$isActiveFlow`.

- [ ] **Step 5: Run tests + full suite**

Run: `php artisan test tests/Feature/Sales/DirectSaleTest.php`
Expected: los 4 tests de modo-nombre PASAN.
Run: `php artisan test tests/Feature/Sales`
Expected: sin regresiones.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Telegram/BotSaleHandler.php app/Services/Telegram/BotHandler.php tests/Feature/Sales/DirectSaleTest.php
git commit -m "feat(sales): venta directa por nombre (interceptor en texto+voz) + desambiguacion"
```

---

## Task 3: Venta posicional (foto/desambiguación → "del segundo")

**Files:**
- Modify: `app/Services/Telegram/BotSaleHandler.php` (implementar `sellByPosition`)
- Test: `tests/Feature/Sales/DirectSaleTest.php` (agregar casos)

La lista de candidatos pendiente puede venir de:
- `busqueda:multiple` (búsqueda por foto/visión o por texto), con `data['results']` = array de
  `['id'=>, 'name'=>, ...]`.
- `venta_directa:elegir`, con `data['ids']` = mapa `"1"=>id`.

- [ ] **Step 1: Implement sellByPosition (reemplaza el stub)**

En `app/Services/Telegram/BotSaleHandler.php`:

```php
    /** Vende el candidato en la posición N de la lista pendiente (foto/desambiguación). */
    private function sellByPosition(string $chatId, \App\DTOs\ParsedSaleCommand $cmd, \App\Models\User $user): bool
    {
        $conv = TelegramConversation::where('chat_id', $chatId)
            ->whereIn('step', ['busqueda:multiple', 'venta_directa:elegir'])
            ->first();

        if (! $conv) {
            $this->telegram->sendMessage($chatId, "❌ No hay una lista para elegir. Busca el producto o saca una foto primero.");
            return true;
        }

        // Resolver product id por posición según el tipo de lista.
        $productId = null;
        $data = $conv->data ?? [];
        if ($conv->step === 'busqueda:multiple') {
            $results = array_values($data['results'] ?? []);
            $productId = $results[$cmd->position - 1]['id'] ?? null;
        } else { // venta_directa:elegir
            $ids = array_values($data['ids'] ?? []);
            $productId = $ids[$cmd->position - 1] ?? null;
        }

        if (! $productId) {
            $this->telegram->sendMessage($chatId, "❌ No hay un producto en la posición {$cmd->position} de la lista.");
            return true;
        }

        $product = \App\Models\Product::find($productId);
        if (! $product) {
            $this->telegram->sendMessage($chatId, "❌ Ese producto ya no existe.");
            return true;
        }

        $conv->delete();
        $this->completeDirectSale($chatId, $product, $cmd, $user->id);
        return true;
    }
```

- [ ] **Step 2: Write the failing positional tests**

Agregar a `tests/Feature/Sales/DirectSaleTest.php`:

```php
    public function test_positional_sale_from_pending_photo_list(): void
    {
        $p1 = $this->stocked('Figura Mario', 2000, 1000, 10);
        $p2 = $this->stocked('Figura Luigi', 2000, 1000, 10);

        // Simula el estado que deja la búsqueda por foto/visión.
        TelegramConversation::getOrCreate('555')->update([
            'step' => 'busqueda:multiple',
            'data' => ['results' => [
                ['id' => $p1->id, 'name' => 'Figura Mario'],
                ['id' => $p2->id, 'name' => 'Figura Luigi'],
            ]],
            'expires_at' => now()->addMinutes(5),
        ]);

        $handled = $this->handler->tryQuickSell('555', 'vende 3 del segundo a 30');

        $this->assertTrue($handled);
        $sale = Sale::first();
        $this->assertNotNull($sale);
        $this->assertSame(9000, $sale->total); // 3 × 3000
        $this->assertSame(7, $p2->fresh()->quantity); // vendió del SEGUNDO (Luigi)
        $this->assertSame(10, $p1->fresh()->quantity); // el primero intacto
    }

    public function test_positional_out_of_range_no_sale(): void
    {
        $p1 = $this->stocked('Figura Mario', 2000, 1000, 10);
        TelegramConversation::getOrCreate('555')->update([
            'step' => 'busqueda:multiple',
            'data' => ['results' => [['id' => $p1->id, 'name' => 'Figura Mario']]],
            'expires_at' => now()->addMinutes(5),
        ]);

        $handled = $this->handler->tryQuickSell('555', 'vende 1 del quinto');
        $this->assertTrue($handled);
        $this->assertSame(0, Sale::count());
    }

    public function test_positional_without_pending_list_no_sale(): void
    {
        $handled = $this->handler->tryQuickSell('555', 'vende 1 del segundo');
        $this->assertTrue($handled);
        $this->assertSame(0, Sale::count());
    }
```

- [ ] **Step 3: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Sales/DirectSaleTest.php`
Expected: PASS (todos: 4 nombre + 3 posicional).

- [ ] **Step 4: Full suite + commit**

Run: `php artisan test tests/Feature/Sales`
Expected: sin regresiones.

```bash
git add app/Services/Telegram/BotSaleHandler.php tests/Feature/Sales/DirectSaleTest.php
git commit -m "feat(sales): venta posicional (foto/desambiguacion -> 'vende N del segundo a P')"
```

---

## Cierre de SP1

- [ ] **Suite completa**: `php artisan test` — verde.
- [ ] **Smoke-test real**: por voz "vende 3 [producto] a 10" → venta directa + /deshacer. Foto de un
  producto → candidatos → "vende 2 del primero a 15" → venta del #1.
- [ ] **Code review** con `superpowers:requesting-code-review` sobre `feature/direct-sale`.

## Cobertura del spec (self-review)

| Requisito | Task |
|-----------|------|
| R1 detección de intención (imperativo, excluye pasado) | Task 1 (`SELL_VERB`) |
| R2 parseo qty/precio/producto | Task 1 |
| R3 método contado/transferencia | Task 1 |
| R4 1 match → venta instantánea + avisos + /deshacer | Task 2 (`completeDirectSale`) |
| R5 varios → lista + número completa | Task 2 (`promptDirectPick`/`handleDirectPick`) |
| R6 0 → "no encontré", sin LLM | Task 2 |
| R7 no-orden → fallback al agente | Task 2 (return false + wiring) |
| R8 texto + voz, sin flujo activo | Task 2 (wiring A/B) |
| R9 venta posicional sobre lista pendiente | Task 3 (`sellByPosition`) |

**Fuera de alcance:** botones inline (SP2). El flujo de foto/visión que genera la lista de candidatos
YA existe (`handlePhotoSearch` → `busqueda:multiple`); SP1 solo lo consume para la venta posicional.
