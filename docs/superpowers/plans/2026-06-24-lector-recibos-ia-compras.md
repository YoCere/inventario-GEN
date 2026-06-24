# Lector de Recibos IA para Compras — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Subir foto de recibo en crear-compra, extraer con IA (visión) fecha/proveedor/líneas, casar productos contra catálogo y prellenar el formulario para revisión manual.

**Architecture:** Endpoint `POST /purchases/parse-receipt` recibe la imagen, `ReceiptParser` hace una llamada one-shot de visión (Anthropic u OpenAI-compatible según Settings) que devuelve JSON estructurado, `ProductMatcher` casa cada línea reusando `ProductSearchService` existente, el controller responde JSON `{ purchase_date, supplier, matched[], unmatched[] }` y el front Alpine prellena la tabla. Nunca auto-guarda.

**Tech Stack:** Laravel 11, PHPUnit (class-based + RefreshDatabase), Alpine.js, TomSelect, `Http::fake()` para tests de IA.

---

## Contrato JSON (respuesta del endpoint)

Todos los `*_price` en **céntimos** (entero ×100).

```json
{
  "purchase_date": "2026-06-20",
  "supplier": { "id": 5, "name": "Distribuidora X" },
  "matched": [
    { "raw_name": "Coca Cola 2L x12", "product_id": 12, "product_name": "Coca Cola 2L",
      "product_code": "SKU-CC2L", "quantity": 12, "unit_price": 150000, "confidence": 0.91 }
  ],
  "unmatched": [
    { "raw_name": "Galleta Oreo", "quantity": 5, "unit_price": 80000 }
  ]
}
```
`purchase_date` y `supplier` pueden ser `null`. `confidence` ∈ [0,1].

## File Structure

- Create: `app/Services/Receipt/ReceiptData.php` — DTOs `ReceiptData` + `ReceiptLine`.
- Create: `app/Services/Receipt/ReceiptParseException.php` — excepción de dominio.
- Create: `app/Services/Receipt/ReceiptParser.php` — llamada one-shot de visión → `ReceiptData`.
- Create: `app/Services/Receipt/ProductMatcher.php` — casa líneas reusando `ProductSearchService`.
- Create: `app/Http/Requests/ParseReceiptRequest.php` — valida la imagen.
- Modify: `app/Http/Controllers/PurchaseController.php` — método `parseReceipt()`.
- Modify: `routes/web.php` — ruta `purchases.parse-receipt`.
- Modify: `resources/views/purchases/form.blade.php` — botón + lógica Alpine.
- Test: `tests/Feature/Receipt/ReceiptParserTest.php`
- Test: `tests/Feature/Receipt/ProductMatcherTest.php`
- Test: `tests/Feature/Receipt/ParseReceiptEndpointTest.php`

---

## Task 1: DTOs `ReceiptData` + `ReceiptLine`

**Files:**
- Create: `app/Services/Receipt/ReceiptData.php`

- [ ] **Step 1: Crear los DTOs**

```php
<?php

namespace App\Services\Receipt;

readonly class ReceiptLine
{
    public function __construct(
        public string $rawName,
        public int $quantity,
        public int $unitPrice, // céntimos
    ) {}
}

readonly class ReceiptData
{
    /** @param ReceiptLine[] $lines */
    public function __construct(
        public ?string $purchaseDate, // 'Y-m-d' o null
        public ?string $supplierName,
        public array $lines,
    ) {}
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/Receipt/ReceiptData.php
git commit -m "feat(receipt): DTOs ReceiptData y ReceiptLine"
```

---

## Task 2: `ReceiptParseException`

**Files:**
- Create: `app/Services/Receipt/ReceiptParseException.php`

- [ ] **Step 1: Crear la excepción**

```php
<?php

namespace App\Services\Receipt;

class ReceiptParseException extends \RuntimeException
{
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/Receipt/ReceiptParseException.php
git commit -m "feat(receipt): ReceiptParseException"
```

---

## Task 3: `ReceiptParser` (visión one-shot)

**Files:**
- Create: `app/Services/Receipt/ReceiptParser.php`
- Test: `tests/Feature/Receipt/ReceiptParserTest.php`

Reusa Settings (`ai_provider`, `anthropic_api_key`, `openai_api_key`, `ai_model`, `ai_api_base_url`) y `CostTracker`. NO usa el tool-loop de `AgentService`. Patrón de llamada Anthropic copiado de `ProductSearchService::aiSearchAnthropic` (headers `x-api-key` + `anthropic-version: 2023-06-01`).

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Receipt;

use App\Models\Setting;
use App\Services\Receipt\ReceiptParser;
use App\Services\Receipt\ReceiptParseException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReceiptParserTest extends TestCase
{
    use RefreshDatabase;

    private function fakeImage(): File
    {
        return File::image('recibo.jpg', 600, 800);
    }

    public function test_anthropic_returns_structured_data_with_prices_in_cents(): void
    {
        Setting::set('ai_provider', 'anthropic');
        Setting::set('anthropic_api_key', 'sk-test');
        Setting::set('ai_model', 'claude-haiku-4-5-20251001');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => 'Aquí está: {"purchase_date":"2026-06-20","supplier_name":"Distribuidora X","items":[{"raw_name":"Coca Cola 2L","quantity":12,"unit_price":1500.50}]}',
                ]],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            ], 200),
        ]);

        $data = app(ReceiptParser::class)->parse($this->fakeImage());

        $this->assertSame('2026-06-20', $data->purchaseDate);
        $this->assertSame('Distribuidora X', $data->supplierName);
        $this->assertCount(1, $data->lines);
        $this->assertSame('Coca Cola 2L', $data->lines[0]->rawName);
        $this->assertSame(12, $data->lines[0]->quantity);
        $this->assertSame(150050, $data->lines[0]->unitPrice); // 1500.50 → céntimos
    }

    public function test_throws_when_no_valid_json_in_response(): void
    {
        Setting::set('ai_provider', 'anthropic');
        Setting::set('anthropic_api_key', 'sk-test');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'No pude leer el recibo.']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200),
        ]);

        $this->expectException(ReceiptParseException::class);
        app(ReceiptParser::class)->parse($this->fakeImage());
    }

    public function test_throws_when_api_key_missing(): void
    {
        Setting::set('ai_provider', 'anthropic');
        Setting::set('anthropic_api_key', '');

        $this->expectException(ReceiptParseException::class);
        app(ReceiptParser::class)->parse($this->fakeImage());
    }

    public function test_openai_compatible_uses_image_url_data_uri(): void
    {
        Setting::set('ai_provider', 'openai_compatible');
        Setting::set('openai_api_key', 'sk-test');
        Setting::set('ai_model', 'gpt-4o-mini');
        Setting::set('ai_api_base_url', 'https://api.openai.com/v1');

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => '{"purchase_date":null,"supplier_name":null,"items":[{"raw_name":"Pan","quantity":3,"unit_price":2.00}]}']]],
                'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 20],
            ], 200),
        ]);

        $data = app(ReceiptParser::class)->parse($this->fakeImage());

        $this->assertNull($data->purchaseDate);
        $this->assertSame(200, $data->lines[0]->unitPrice);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $content = $body['messages'][0]['content'];
            $hasImage = collect($content)->contains(fn ($c) =>
                ($c['type'] ?? '') === 'image_url'
                && str_starts_with($c['image_url']['url'] ?? '', 'data:image/')
            );
            return $hasImage;
        });
    }
}
```

- [ ] **Step 2: Verificar que falla**

Run: `php artisan test tests/Feature/Receipt/ReceiptParserTest.php`
Expected: FAIL — `Class "App\Services\Receipt\ReceiptParser" not found` (verificar primero que `Setting::set` existe; si la API del modelo Setting difiere, ajustar a la real, ver `app/Models/Setting.php`).

- [ ] **Step 3: Implementar `ReceiptParser`**

```php
<?php

namespace App\Services\Receipt;

use App\Models\Setting;
use App\Services\Agent\CostTracker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReceiptParser
{
    private const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';
    private const PROMPT = <<<'TXT'
Eres un extractor de datos de recibos/facturas de compra de un negocio.
Analiza la imagen y devuelve ÚNICAMENTE un objeto JSON válido (sin texto extra, sin markdown) con esta forma exacta:
{"purchase_date":"YYYY-MM-DD o null","supplier_name":"nombre o null","items":[{"raw_name":"texto del producto tal como aparece","quantity":entero,"unit_price":precio_unitario_decimal}]}
Reglas:
- unit_price es el precio por unidad (no el subtotal de la línea) en la moneda del recibo, como número decimal (ej 1500.50).
- quantity es entero (si no hay cantidad clara, usa 1).
- Si un dato no aparece, usa null (para fecha/proveedor) o tu mejor estimación para items.
- No inventes productos que no estén en el recibo.
TXT;

    public function __construct(private CostTracker $costTracker) {}

    public function parse(UploadedFile $image): ReceiptData
    {
        if (! $this->costTracker->withinDailyLimit()) {
            throw new ReceiptParseException('Límite diario de costo IA alcanzado.');
        }

        $provider = Setting::get('ai_provider', 'anthropic');
        $base64   = base64_encode(file_get_contents($image->getRealPath()));
        $mime     = $image->getMimeType() ?: 'image/jpeg';

        $raw = $provider === 'openai_compatible'
            ? $this->callOpenAi($base64, $mime)
            : $this->callAnthropic($base64, $mime);

        return $this->toReceiptData($raw);
    }

    private function callAnthropic(string $base64, string $mime): string
    {
        $apiKey = (string) Setting::get('anthropic_api_key', '');
        if ($apiKey === '') {
            throw new ReceiptParseException('Configura la API key de Anthropic en Ajustes IA.');
        }
        $model = (string) Setting::get('ai_model', 'claude-haiku-4-5-20251001');

        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(60)->post(self::ANTHROPIC_URL, [
            'model'      => $model,
            'max_tokens' => 2048,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $base64]],
                    ['type' => 'text', 'text' => self::PROMPT],
                ],
            ]],
        ]);

        if ($response->failed()) {
            Log::error('ReceiptParser Anthropic error', ['status' => $response->status(), 'body' => $response->body()]);
            throw new ReceiptParseException('Error al leer el recibo con IA. Verifica que el modelo configurado soporte imágenes (ej. Claude).');
        }

        $usage = $response->json('usage', []);
        $this->costTracker->record(
            userId: auth()->id(), chatId: null, model: $model, channel: 'web',
            action: 'receipt.parse',
            tokensIn: $usage['input_tokens'] ?? 0, tokensOut: $usage['output_tokens'] ?? 0,
            summary: 'parse recibo compra',
        );

        $parts = collect($response->json('content', []))
            ->where('type', 'text')->pluck('text')->implode("\n");
        return trim($parts);
    }

    private function callOpenAi(string $base64, string $mime): string
    {
        $apiKey = (string) Setting::get('openai_api_key', '');
        if ($apiKey === '') {
            throw new ReceiptParseException('Configura la API key de IA en Ajustes IA.');
        }
        $model   = (string) Setting::get('ai_model', 'gpt-4o-mini');
        $baseUrl = rtrim((string) Setting::get('ai_api_base_url', 'https://api.openai.com/v1'), '/');

        $response = Http::withHeaders(['Authorization' => 'Bearer ' . $apiKey])
            ->timeout(60)->post($baseUrl . '/chat/completions', [
                'model'      => $model,
                'max_tokens' => 2048,
                'messages'   => [[
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => self::PROMPT],
                        ['type' => 'image_url', 'image_url' => ['url' => "data:{$mime};base64,{$base64}"]],
                    ],
                ]],
            ]);

        if ($response->failed()) {
            Log::error('ReceiptParser OpenAI error', ['status' => $response->status(), 'body' => $response->body()]);
            throw new ReceiptParseException('Error al leer el recibo con IA. Verifica que el modelo configurado soporte imágenes.');
        }

        $usage = $response->json('usage', []);
        $this->costTracker->record(
            userId: auth()->id(), chatId: null, model: $model, channel: 'web',
            action: 'receipt.parse',
            tokensIn: $usage['prompt_tokens'] ?? 0, tokensOut: $usage['completion_tokens'] ?? 0,
            summary: 'parse recibo compra',
        );

        return (string) $response->json('choices.0.message.content', '');
    }

    private function toReceiptData(string $raw): ReceiptData
    {
        // Extraer el primer objeto JSON aunque venga envuelto en texto/markdown.
        if (! preg_match('/\{.*\}/s', $raw, $m)) {
            throw new ReceiptParseException('La IA no devolvió datos legibles del recibo.');
        }
        $json = json_decode($m[0], true);
        if (! is_array($json) || ! isset($json['items']) || ! is_array($json['items'])) {
            throw new ReceiptParseException('La IA no devolvió datos legibles del recibo.');
        }

        $lines = [];
        foreach ($json['items'] as $item) {
            $name = trim((string) ($item['raw_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $qty   = max(1, (int) ($item['quantity'] ?? 1));
            $price = (int) round(((float) ($item['unit_price'] ?? 0)) * 100); // decimal → céntimos
            $lines[] = new ReceiptLine($name, $qty, $price);
        }

        $date = $json['purchase_date'] ?? null;
        $date = (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) ? $date : null;

        $supplier = $json['supplier_name'] ?? null;
        $supplier = (is_string($supplier) && trim($supplier) !== '') ? trim($supplier) : null;

        return new ReceiptData($date, $supplier, $lines);
    }
}
```

- [ ] **Step 4: Verificar que pasa**

Run: `php artisan test tests/Feature/Receipt/ReceiptParserTest.php`
Expected: PASS (4 tests). Si `Setting::set` no existe, usar el método real de seteo (revisar `app/Models/Setting.php`); si `CostTracker::record` tiene firma distinta, alinear con `app/Services/Agent/CostTracker.php`.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Receipt/ReceiptParser.php tests/Feature/Receipt/ReceiptParserTest.php
git commit -m "feat(receipt): ReceiptParser visión one-shot (Anthropic + OpenAI-compatible)"
```

---

## Task 4: `ProductMatcher` (reusa ProductSearchService)

**Files:**
- Create: `app/Services/Receipt/ProductMatcher.php`
- Test: `tests/Feature/Receipt/ProductMatcherTest.php`

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Receipt;

use App\Models\Product;
use App\Services\Receipt\ProductMatcher;
use App\Services\Receipt\ReceiptData;
use App\Services\Receipt\ReceiptLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_existing_product_and_separates_unmatched(): void
    {
        $coca = Product::factory()->create(['name' => 'Coca Cola 2L', 'sku' => 'CC2L', 'is_active' => true]);

        $data = new ReceiptData('2026-06-20', null, [
            new ReceiptLine('Coca Cola 2L', 12, 150000),
            new ReceiptLine('Producto Inexistente XYZ', 5, 80000),
        ]);

        $result = app(ProductMatcher::class)->match($data);

        $this->assertCount(1, $result['matched']);
        $this->assertSame($coca->id, $result['matched'][0]['product_id']);
        $this->assertSame(12, $result['matched'][0]['quantity']);
        $this->assertSame(150000, $result['matched'][0]['unit_price']);

        $this->assertCount(1, $result['unmatched']);
        $this->assertSame('Producto Inexistente XYZ', $result['unmatched'][0]['raw_name']);
    }
}
```

- [ ] **Step 2: Verificar que falla**

Run: `php artisan test tests/Feature/Receipt/ProductMatcherTest.php`
Expected: FAIL — `Class "App\Services\Receipt\ProductMatcher" not found`.

- [ ] **Step 3: Implementar `ProductMatcher`**

```php
<?php

namespace App\Services\Receipt;

use App\Services\Messaging\ProductSearchService;

class ProductMatcher
{
    public function __construct(private ProductSearchService $search) {}

    /**
     * @return array{matched: array<int,array>, unmatched: array<int,array>}
     */
    public function match(ReceiptData $data): array
    {
        $matched = [];
        $unmatched = [];

        foreach ($data->lines as $line) {
            $hit = $this->search->searchProducts($line->rawName, publicOnly: false)->first();

            if ($hit) {
                $matched[] = [
                    'raw_name'     => $line->rawName,
                    'product_id'   => $hit->id,
                    'product_name' => $hit->name,
                    'product_code' => $hit->sku,
                    'quantity'     => $line->quantity,
                    'unit_price'   => $line->unitPrice,
                    'confidence'   => $this->confidence($line->rawName, $hit->name),
                ];
            } else {
                $unmatched[] = [
                    'raw_name'   => $line->rawName,
                    'quantity'   => $line->quantity,
                    'unit_price' => $line->unitPrice,
                ];
            }
        }

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    private function confidence(string $a, string $b): float
    {
        similar_text(mb_strtolower($a), mb_strtolower($b), $percent);
        return round($percent / 100, 2);
    }
}
```

- [ ] **Step 4: Verificar que pasa**

Run: `php artisan test tests/Feature/Receipt/ProductMatcherTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Receipt/ProductMatcher.php tests/Feature/Receipt/ProductMatcherTest.php
git commit -m "feat(receipt): ProductMatcher reusando ProductSearchService"
```

---

## Task 5: `ParseReceiptRequest` + endpoint `parseReceipt` + ruta

**Files:**
- Create: `app/Http/Requests/ParseReceiptRequest.php`
- Modify: `app/Http/Controllers/PurchaseController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Receipt/ParseReceiptEndpointTest.php`

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Receipt;

use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ParseReceiptEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_matched_and_unmatched_from_receipt(): void
    {
        $user = User::factory()->staff()->create();
        Product::factory()->create(['name' => 'Coca Cola 2L', 'sku' => 'CC2L', 'is_active' => true]);

        Setting::set('ai_provider', 'anthropic');
        Setting::set('anthropic_api_key', 'sk-test');

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => '{"purchase_date":"2026-06-20","supplier_name":null,"items":[{"raw_name":"Coca Cola 2L","quantity":12,"unit_price":1500.00},{"raw_name":"Producto XYZ","quantity":2,"unit_price":50.00}]}']],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            ], 200),
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('purchases.parse-receipt'), [
                'receipt' => File::image('recibo.jpg', 600, 800),
            ]);

        $response->assertOk();
        $response->assertJsonPath('purchase_date', '2026-06-20');
        $response->assertJsonCount(1, 'matched');
        $response->assertJsonCount(1, 'unmatched');
        $response->assertJsonPath('matched.0.unit_price', 150000);
    }

    public function test_rejects_non_image(): void
    {
        $user = User::factory()->staff()->create();

        $response = $this->actingAs($user)
            ->postJson(route('purchases.parse-receipt'), [
                'receipt' => File::create('document.pdf', 10),
            ]);

        $response->assertStatus(422);
    }

    public function test_parse_error_returns_json_error(): void
    {
        $user = User::factory()->staff()->create();
        Setting::set('ai_provider', 'anthropic');
        Setting::set('anthropic_api_key', '');

        $response = $this->actingAs($user)
            ->postJson(route('purchases.parse-receipt'), [
                'receipt' => File::image('recibo.jpg', 600, 800),
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['error']);
    }
}
```

- [ ] **Step 2: Verificar que falla**

Run: `php artisan test tests/Feature/Receipt/ParseReceiptEndpointTest.php`
Expected: FAIL — ruta `purchases.parse-receipt` no existe.

- [ ] **Step 3: Crear `ParseReceiptRequest`**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ParseReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'receipt' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'], // 8MB
        ];
    }
}
```

- [ ] **Step 4: Añadir `parseReceipt()` al controller**

En `app/Http/Controllers/PurchaseController.php`, añadir imports arriba:

```php
use App\Http\Requests\ParseReceiptRequest;
use App\Services\Receipt\ReceiptParser;
use App\Services\Receipt\ProductMatcher;
use App\Services\Receipt\ReceiptParseException;
use App\Models\Supplier;
```

Y el método (después de `store()`):

```php
public function parseReceipt(
    ParseReceiptRequest $request,
    ReceiptParser $parser,
    ProductMatcher $matcher,
): \Illuminate\Http\JsonResponse {
    try {
        $data   = $parser->parse($request->file('receipt'));
        $result = $matcher->match($data);

        $supplier = null;
        if ($data->supplierName) {
            $found = Supplier::whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($data->supplierName) . '%'])->first();
            if ($found) {
                $supplier = ['id' => $found->id, 'name' => $found->name];
            }
        }

        return response()->json([
            'purchase_date' => $data->purchaseDate,
            'supplier'      => $supplier,
            'matched'       => $result['matched'],
            'unmatched'     => $result['unmatched'],
        ]);
    } catch (ReceiptParseException $e) {
        return response()->json(['error' => $e->getMessage()], 422);
    } catch (\Throwable $e) {
        \Log::error('parseReceipt error', ['error' => $e->getMessage()]);
        return response()->json(['error' => 'No se pudo procesar el recibo.'], 500);
    }
}
```

- [ ] **Step 5: Añadir la ruta**

En `routes/web.php`, justo ANTES de `Route::resource('purchases', PurchaseController::class);` (para que no la capture el `{purchase}` show), añadir:

```php
Route::post('purchases/parse-receipt', [PurchaseController::class, 'parseReceipt'])->name('purchases.parse-receipt');
```

- [ ] **Step 6: Verificar que pasa**

Run: `php artisan test tests/Feature/Receipt/ParseReceiptEndpointTest.php`
Expected: PASS (3 tests). Si `Supplier` no tiene columna `name`, ajustar. Si el gate de compras exige permiso específico (revisar middleware del grupo en `routes/web.php`), el test `staff()` debe poder; si no, usar `admin()`.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/ParseReceiptRequest.php app/Http/Controllers/PurchaseController.php routes/web.php tests/Feature/Receipt/ParseReceiptEndpointTest.php
git commit -m "feat(receipt): endpoint POST purchases/parse-receipt"
```

---

## Task 6: Frontend — botón + lógica Alpine

**Files:**
- Modify: `resources/views/purchases/form.blade.php`

No hay test automatizado de UI; verificación manual al final.

- [ ] **Step 1: Añadir botón bajo el campo `proof_image`**

En `resources/views/purchases/form.blade.php`, dentro del bloque `<!-- Imagen de Comprobante -->` (después de `<x-input-error :messages="$errors->get('proof_image')" />`, antes del `<div class="mt-2">` del preview), insertar:

```blade
            <button
                type="button"
                @click="analyzeReceipt()"
                :disabled="analyzing"
                class="mt-2 inline-flex items-center gap-2 rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50">
                <svg x-show="analyzing" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span x-text="analyzing ? 'Analizando recibo…' : '📷 Analizar recibo con IA'"></span>
            </button>
```

- [ ] **Step 2: Añadir estado y método al objeto Alpine `purchaseForm`**

En el `Alpine.data('purchaseForm', ...)`, añadir a las propiedades (junto a `loading: false,`):

```js
            analyzing: false,
            unmatchedItems: [],
```

Y añadir el método (después de `addProduct(product) { ... }`):

```js
            async analyzeReceipt() {
                if (this.analyzing) return;
                const input = document.getElementById('proof_image');
                const file = input?.files?.[0];
                if (!file) {
                    window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Selecciona una imagen del recibo primero.', type: 'info' } }));
                    return;
                }

                this.analyzing = true;
                const fd = new FormData();
                fd.append('receipt', file);

                try {
                    const res = await fetch('{{ route("purchases.parse-receipt") }}', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') },
                        body: fd,
                    });
                    const data = await res.json();

                    if (!res.ok) {
                        window.dispatchEvent(new CustomEvent('toast', { detail: { message: data.error || 'No se pudo leer el recibo.', type: 'error' } }));
                        return;
                    }

                    // Fecha
                    if (data.purchase_date) {
                        const dateEl = document.getElementById('purchase_date');
                        if (dateEl) dateEl.value = data.purchase_date;
                    }
                    // Proveedor (solo si casó uno existente)
                    if (data.supplier && data.supplier.id) {
                        this.supplier_id = String(data.supplier.id);
                    }
                    // Productos casados → tabla
                    (data.matched || []).forEach(m => {
                        this.addProduct({
                            value: m.product_id,
                            text: m.product_name,
                            sku: m.product_code,
                            price: m.unit_price,       // céntimos
                            selling_price: 0,
                        });
                        const idx = this.items.findIndex(i => i.product_id == m.product_id);
                        if (idx !== -1) {
                            this.items[idx].quantity = m.quantity;
                            this.items[idx].unit_price = m.unit_price;
                            this.calculateLine(idx);
                        }
                    });
                    // No reconocidos
                    this.unmatchedItems = data.unmatched || [];

                    const n = (data.matched || []).length;
                    window.dispatchEvent(new CustomEvent('toast', {
                        detail: { message: `${n} producto(s) reconocido(s). Revisa antes de guardar.`, type: 'success' }
                    }));
                } catch (e) {
                    window.dispatchEvent(new CustomEvent('toast', { detail: { message: 'Error de red al analizar el recibo.', type: 'error' } }));
                } finally {
                    this.analyzing = false;
                }
            },
```

- [ ] **Step 3: Añadir sección "Revisar manualmente" (no reconocidos)**

Justo después del `<!-- Sección de Productos -->` cierre de la tabla del carrito (después del `</div>` que cierra el bloque `bg-white rounded-lg shadow ...` de la tabla, antes de `<!-- Acciones -->`), insertar:

```blade
        <!-- Productos del recibo no reconocidos -->
        <template x-if="unmatchedItems.length > 0">
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-4">
                <p class="text-sm font-semibold text-amber-800 mb-2">No reconocidos del recibo (búscalos manualmente arriba o ignóralos):</p>
                <ul class="space-y-1">
                    <template x-for="(u, i) in unmatchedItems" :key="i">
                        <li class="flex items-center justify-between text-sm text-amber-900">
                            <span>
                                <span x-text="u.raw_name" class="font-medium"></span>
                                <span class="text-amber-700" x-text="' · cant: ' + u.quantity + ' · precio: ' + window.formatMoney(u.unit_price)"></span>
                            </span>
                            <button type="button" @click="unmatchedItems.splice(i, 1)" class="text-amber-600 hover:text-amber-800 text-xs">Quitar</button>
                        </li>
                    </template>
                </ul>
            </div>
        </template>
```

- [ ] **Step 4: Verificación manual**

Levantar la app, ir a crear compra, seleccionar una foto de recibo, click "Analizar recibo con IA". Requiere `ai_provider`=anthropic con `anthropic_api_key` y `ai_model` con visión (ej. claude). Confirmar: fecha prellenada, productos casados en tabla con cantidad/precio, no reconocidos en sección ámbar. Verificar guardado normal de la compra.

Nota: cámara/imagen en web requiere **HTTPS** o localhost.

- [ ] **Step 5: Commit**

```bash
git add resources/views/purchases/form.blade.php
git commit -m "feat(receipt): UI analizar recibo IA en crear compra"
```

---

## Self-Review (cubierto)

- **Cobertura spec:** flujo (T5/T6), ReceiptParser visión (T3), ProductMatcher reuso (T4), constraint modelo visión → mensajes de error (T3/T5), matched/unmatched (T4/T6), nunca auto-guarda (T6), errores (T3/T5 + toasts T6). Proveedor sugerido si existe (T5). Precio venta queda 0 (T6).
- **Tipos consistentes:** `ReceiptData.lines: ReceiptLine[]`, `unitPrice` céntimos en todo el flujo; contrato JSON `matched[].unit_price` céntimos consumido por Alpine `addProduct(price)`.
- **Sin placeholders:** todo el código está completo.

## Notas para el implementador (verificar contra el código real)

- `Setting::set(...)` / `Setting::get(...)`: confirmar API real en `app/Models/Setting.php`. Si no hay `set`, sembrar vía `Setting::create`/factory en tests.
- `CostTracker::record(...)`: firma con args nombrados; ya validada contra `app/Services/Agent/CostTracker.php` (incluye `channel`, `action`, `summary`).
- `User::factory()->staff()`: usado en tests existentes ([ProductSearchCacheTest](../../../tests/Feature/Products/ProductSearchCacheTest.php)). Si el endpoint queda tras un gate más estricto, usar `admin()`.
- `window.formatMoney`: helper global ya usado en el form para céntimos.
