# Venta rápida instantánea — Plan de implementación (Fase 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Vender por Telegram con una sola frase en lenguaje natural (producto + cantidad + precio negociado), ejecutando la venta al instante, y poder deshacerla con `/deshacer`.

**Architecture:** Un servicio fino `QuickSaleService` envuelve el `SaleService` existente: `sell()` arma el `SaleData` con defaults y llama a `createSale` (que ya descuenta stock y postea contabilidad); `void()`/`voidLast()` aplican las reglas de permiso/ventana y llaman a `cancelSale` (que ya restaura stock y revierte los asientos). El agente IA usa un tool nuevo `SellProductTool` (Telegram-only, `webExposed()=false`) para interpretar la orden y llamar a `sell()`. Un comando `/deshacer` y un tool `cancel_last_sale` llaman a `voidLast()`.

**Tech Stack:** Laravel 11, PHP 8.x, MySQL, PHPUnit, enums `PaymentMethod`/`SaleStatus`, `SaleData` DTO, `ProductSearchService`.

**Alcance Fase 1:** motor (`sell`+`void`+`voidLast`), tool NL de venta, comando/tool de deshacer. NO incluye el POS web "Cobrar rápido" (Fase 2), el botón "Vender" instantáneo (Fase 3), ni la foto de comprobante de transferencia (Fase 2, R11).

**Regla MySQL (memoria):** nunca `migrate:fresh`. Esta fase NO agrega migraciones.

---

## Estructura de archivos

| Archivo | Responsabilidad |
|---------|-----------------|
| `app/Services/QuickSaleService.php` | Motor: `sell()`, `void()`, `voidLast()` sobre `SaleService` |
| `app/Services/Agent/Tools/SellProductTool.php` | Tool IA: interpreta orden NL → resuelve producto → `sell()` |
| `app/Services/Agent/Tools/CancelLastSaleTool.php` | Tool IA: "anula la última venta" → `voidLast()` |
| `app/Services/Telegram/BotHandler.php` | Comando `/deshacer` (modificar) |
| `app/Providers/AppServiceProvider.php` | Registrar los 2 tools (modificar) |
| `tests/Feature/Sales/QuickSaleServiceSellTest.php` | Tests de `sell()` |
| `tests/Feature/Sales/QuickSaleServiceVoidTest.php` | Tests de `void()`/`voidLast()` |
| `tests/Feature/Sales/SellProductToolTest.php` | Tests del tool NL de venta |
| `tests/Feature/Sales/CancelLastSaleToolTest.php` | Tests del tool de deshacer |

**Setup de stock en tests** (patrón existente en `tests/Feature/Sales/SaleServiceTest.php`): crear `Warehouse` + `Location`, `Product::factory` con `quantity 0`, `ProductStock::create` con la cantidad, y `product->update(['quantity' => N])`. `createSale` descuenta de esa location.

**IMPORTANTE — seeders contables en cada test que crea ventas:** `createSale` exige un periodo
contable abierto + plan de cuentas + settings. Todo `setUp()` que venda debe incluir, al inicio:
```php
$this->seed([
    \Database\Seeders\AccountingPeriodSeeder::class,
    \Database\Seeders\ChartOfAccountSeeder::class,
    \Database\Seeders\SettingSeeder::class,
]);
```
(Sin esto, la venta lanza `SaleException: "No existe un periodo contable abierto..."`. Confirmar los
nombres exactos de los seeders en el `setUp()` de `tests/Feature/Sales/SaleServiceTest.php`.)

---

## Task 1: QuickSaleService::sell()

**Files:**
- Create: `app/Services/QuickSaleService.php`
- Test: `tests/Feature/Sales/QuickSaleServiceSellTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Sales/QuickSaleServiceSellTest.php`:

```php
<?php

namespace Tests\Feature\Sales;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\QuickSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickSaleServiceSellTest extends TestCase
{
    use RefreshDatabase;

    private QuickSaleService $service;
    private Product $product;
    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(QuickSaleService::class);
        $this->seller = User::factory()->staff()->create();

        $warehouse = Warehouse::create(['name' => 'Almacén', 'code' => 'ALM']);
        $location = Location::create(['name' => 'Estante', 'code' => 'EST', 'warehouse_id' => $warehouse->id]);

        $this->product = Product::factory()->create([
            'selling_price'  => 2000, // Bs 20.00
            'purchase_price' => 1800, // Bs 18.00
            'quantity'       => 0,
        ]);
        ProductStock::create([
            'product_id'  => $this->product->id,
            'location_id' => $location->id,
            'quantity'    => 10,
        ]);
        $this->product->update(['quantity' => 10]);
    }

    public function test_sell_creates_completed_sale_and_decrements_stock(): void
    {
        $result = $this->service->sell($this->product, 3, null, PaymentMethod::CASH, 0, $this->seller->id);

        $sale = $result['sale'];
        $this->assertSame(SaleStatus::COMPLETED, $sale->status);
        $this->assertSame(6000, $sale->total); // 3 × 2000
        $this->assertSame(7, $this->product->fresh()->quantity); // 10 - 3
        $this->assertFalse($result['below_cost']);
    }

    public function test_sell_records_price_override_and_flags_below_cost(): void
    {
        // Vender a Bs 15 (1500) c/u cuando la lista es 20 (2000) y el costo 18 (1800) → bajo costo.
        // NOTA: SaleService::createSale NO honra items[].unit_price; usa selling_price - discount.
        // Por eso el override se traduce a descuento por unidad. Verificamos el efecto real (total).
        $result = $this->service->sell($this->product, 3, 1500, PaymentMethod::CASH, 0, $this->seller->id);

        $sale = $result['sale'];
        $this->assertSame(4500, $sale->total);    // 3 × 1500 efectivo
        $this->assertTrue($result['below_cost']); // 1500 < 1800
    }

    public function test_sell_applies_discount(): void
    {
        // total 2 × 2000 = 4000, descuento 500 → 3500
        $result = $this->service->sell($this->product, 2, null, PaymentMethod::CASH, 500, $this->seller->id);

        $this->assertSame(3500, $result['sale']->total);
    }

    public function test_sell_throws_on_insufficient_stock(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->sell($this->product, 99, null, PaymentMethod::CASH, 0, $this->seller->id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Sales/QuickSaleServiceSellTest.php`
Expected: FAIL — class `App\Services\QuickSaleService` not found.

- [ ] **Step 3: Write the implementation**

`app/Services/QuickSaleService.php`:

```php
<?php

namespace App\Services;

use App\DTOs\SaleData;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;

/**
 * Motor de venta/anulación instantánea. Capa fina sobre SaleService: reúne los
 * defaults del "camino rápido" y delega la lógica de dominio (stock + contabilidad)
 * a createSale/cancelSale, que ya son atómicos y reversibles.
 */
class QuickSaleService
{
    public const UNDO_WINDOW_MINUTES = 15;

    public function __construct(private SaleService $sales) {}

    /**
     * Crea una venta completada de un solo producto al instante.
     *
     * @return array{sale: Sale, below_cost: bool}
     */
    public function sell(
        Product $product,
        int $qty,
        ?int $unitPriceCents,
        PaymentMethod $method,
        int $discountCents,
        int $actorId,
        string $source = 'telegram',
    ): array {
        if ($qty <= 0) {
            throw new \RuntimeException('La cantidad debe ser mayor a 0.');
        }
        if ($qty > $product->quantity) {
            throw new \RuntimeException("Stock insuficiente: hay {$product->quantity} disponibles.");
        }

        // SaleService::createSale ignora items[].unit_price: cotiza con selling_price y
        // resta el `discount` por unidad. Traducimos el precio negociado (más barato) a
        // un descuento por unidad sobre la lista. No permite vender por encima de la lista
        // (para eso se sube el precio del producto) — caso raro, fuera de alcance.
        $listPrice       = $product->selling_price;
        $requestedUnit   = $unitPriceCents ?? $listPrice;
        $perUnitDiscount = max(0, $listPrice - $requestedUnit);
        $effectiveUnit   = $listPrice - $perUnitDiscount; // = min(requested, list)

        $belowCost = $effectiveUnit < $product->purchase_price;
        $lineTotal = $effectiveUnit * $qty;
        $total     = max(0, $lineTotal - $discountCents);

        $saleData = SaleData::fromArray([
            'created_by'      => $actorId,
            'sale_date'       => now()->toDateTimeString(),
            'status'          => SaleStatus::COMPLETED->value,
            'payment_method'  => $method->value,
            'source'          => $source,
            'notes'           => 'Venta rápida',
            'cash_received'   => $total,
            'change'          => 0,
            'global_discount' => $discountCents,
            'customer_id'     => null,
            'items'           => [[
                'product_id' => $product->id,
                'quantity'   => $qty,
                'unit_price' => $listPrice,
                'discount'   => $perUnitDiscount,
            ]],
        ]);

        $sale = $this->sales->createSale($saleData);

        return ['sale' => $sale, 'below_cost' => $belowCost];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Sales/QuickSaleServiceSellTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/QuickSaleService.php tests/Feature/Sales/QuickSaleServiceSellTest.php
git commit -m "feat(sales): QuickSaleService::sell (venta instantanea + below_cost)"
```

---

## Task 2: QuickSaleService::void() y voidLast()

**Files:**
- Modify: `app/Services/QuickSaleService.php`
- Test: `tests/Feature/Sales/QuickSaleServiceVoidTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Sales/QuickSaleServiceVoidTest.php`:

```php
<?php

namespace Tests\Feature\Sales;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\QuickSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickSaleServiceVoidTest extends TestCase
{
    use RefreshDatabase;

    private QuickSaleService $service;
    private Product $product;
    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(QuickSaleService::class);
        $this->seller = User::factory()->staff()->create();

        $warehouse = Warehouse::create(['name' => 'Almacén', 'code' => 'ALM']);
        $location = Location::create(['name' => 'Estante', 'code' => 'EST', 'warehouse_id' => $warehouse->id]);
        $this->product = Product::factory()->create(['selling_price' => 2000, 'purchase_price' => 1800, 'quantity' => 0]);
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $location->id, 'quantity' => 10]);
        $this->product->update(['quantity' => 10]);
    }

    private function sell(?int $actorId = null): Sale
    {
        return $this->service->sell($this->product, 2, null, PaymentMethod::CASH, 0, $actorId ?? $this->seller->id)['sale'];
    }

    public function test_void_own_recent_sale_restores_stock(): void
    {
        $sale = $this->sell();
        $this->assertSame(8, $this->product->fresh()->quantity);

        $this->service->void($sale, $this->seller);

        $this->assertSame(SaleStatus::CANCELLED, $sale->fresh()->status);
        $this->assertSame(10, $this->product->fresh()->quantity); // stock restaurado
    }

    public function test_non_admin_cannot_void_another_sellers_sale(): void
    {
        $other = User::factory()->staff()->create();
        $sale = $this->sell($other->id);

        $this->expectException(\RuntimeException::class);
        try {
            $this->service->void($sale, $this->seller);
        } finally {
            $this->assertSame(SaleStatus::COMPLETED, $sale->fresh()->status);
        }
    }

    public function test_non_admin_cannot_void_outside_window(): void
    {
        $sale = $this->sell();
        $sale->update(['created_at' => now()->subMinutes(QuickSaleService::UNDO_WINDOW_MINUTES + 1)]);

        $this->expectException(\RuntimeException::class);
        $this->service->void($sale->fresh(), $this->seller);
    }

    public function test_admin_can_void_any_sale_anytime(): void
    {
        $admin = User::factory()->admin()->create();
        $sale = $this->sell();
        $sale->update(['created_at' => now()->subHours(5)]);

        $this->service->void($sale->fresh(), $admin);

        $this->assertSame(SaleStatus::CANCELLED, $sale->fresh()->status);
    }

    public function test_void_last_cancels_the_most_recent_sale(): void
    {
        $first = $this->sell();
        $second = $this->sell();

        $cancelled = $this->service->voidLast($this->seller);

        $this->assertSame($second->id, $cancelled->id);
        $this->assertSame(SaleStatus::COMPLETED, $first->fresh()->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Sales/QuickSaleServiceVoidTest.php`
Expected: FAIL — method `void` not defined.

- [ ] **Step 3: Add void() and voidLast() to QuickSaleService**

Agregar dentro de `class QuickSaleService` (después de `sell()`), y el `use` de `Sale`/`User` ya está:

```php
    /**
     * Anula una venta completada aplicando las reglas de permiso/ventana.
     * Reutiliza SaleService::cancelSale (restaura stock + revierte asientos).
     */
    public function void(Sale $sale, User $actor): Sale
    {
        if ($sale->status !== SaleStatus::COMPLETED) {
            throw new \RuntimeException('Esa venta no se puede deshacer (ya anulada o no completada).');
        }

        if (! $actor->isAdmin()) {
            if ((int) $sale->created_by !== (int) $actor->id) {
                throw new \RuntimeException('Solo puedes deshacer tus propias ventas.');
            }
            if ($sale->created_at->lt(now()->subMinutes(self::UNDO_WINDOW_MINUTES))) {
                throw new \RuntimeException('Pasó la ventana de ' . self::UNDO_WINDOW_MINUTES . ' minutos para deshacer esta venta.');
            }
        }

        return $this->sales->cancelSale($sale, 'Deshacer venta rápida');
    }

    /** Anula la última venta completada del actor (o la última global si es admin). */
    public function voidLast(User $actor): Sale
    {
        $query = Sale::where('status', SaleStatus::COMPLETED->value);
        if (! $actor->isAdmin()) {
            $query->where('created_by', $actor->id);
        }

        $sale = $query->latest('id')->first();
        if (! $sale) {
            throw new \RuntimeException('No hay una venta reciente para deshacer.');
        }

        return $this->void($sale, $actor);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Sales/QuickSaleServiceVoidTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/QuickSaleService.php tests/Feature/Sales/QuickSaleServiceVoidTest.php
git commit -m "feat(sales): QuickSaleService void/voidLast con ventana + permisos"
```

---

## Task 3: SellProductTool (tool IA de venta NL)

**Files:**
- Create: `app/Services/Agent/Tools/SellProductTool.php`
- Modify: `app/Providers/AppServiceProvider.php` (registrar)
- Test: `tests/Feature/Sales/SellProductToolTest.php`

El tool resuelve el producto con `ProductSearchService::searchProducts($query, publicOnly: false)`
(devuelve `Collection<Product>`). 0 → error; >1 → `needs_selection`; 1 → vende.

- [ ] **Step 1: Write the tool**

`app/Services/Agent/Tools/SellProductTool.php`:

```php
<?php

namespace App\Services\Agent\Tools;

use App\Enums\PaymentMethod;
use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;
use App\Services\Messaging\ProductSearchService;
use App\Services\QuickSaleService;

class SellProductTool extends BaseTool
{
    public function __construct(
        private ProductSearchService $search,
        private QuickSaleService $quickSale,
    ) {}

    public function webExposed(): bool
    {
        return false; // Nunca accesible desde el asistente web.
    }

    public function name(): string
    {
        return 'sell_product';
    }

    public function description(): string
    {
        return 'Registra una venta al instante. Interpreta la orden del vendedor. '
            . 'Reglas de precio: "a X bs" = precio POR UNIDAD → usa unit_price; '
            . '"en total X" = precio total del renglón → usa total_price; sin precio → precio de lista. '
            . 'payment_method: cash (contado, por defecto) o transfer. '
            . 'Si el producto es ambiguo, primero usa search_products y pregunta cuál.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product'        => ['type' => 'string', 'description' => 'Nombre o SKU del producto'],
                'quantity'       => ['type' => 'integer', 'description' => 'Cantidad (por defecto 1)'],
                'unit_price'     => ['type' => 'number', 'description' => 'Precio por unidad en Bs (opcional, override)'],
                'total_price'    => ['type' => 'number', 'description' => 'Precio total del renglón en Bs (opcional, alternativo a unit_price)'],
                'payment_method' => ['type' => 'string', 'enum' => ['cash', 'transfer'], 'description' => 'Método de pago'],
                'discount'       => ['type' => 'number', 'description' => 'Descuento en Bs sobre el total (opcional)'],
            ],
            'required' => ['product'],
        ];
    }

    public function execute(array $input, AgentContext $context): array
    {
        if (! $context->user) {
            return ['error' => 'No hay usuario autenticado.'];
        }

        $query = trim((string) ($input['product'] ?? ''));
        if ($query === '') {
            return ['error' => 'Falta el nombre del producto.'];
        }

        $matches = $this->search->searchProducts($query, publicOnly: false);
        if ($matches->isEmpty()) {
            return ['error' => "No encontré ningún producto para \"{$query}\"."];
        }
        if ($matches->count() > 1) {
            return [
                'needs_selection' => true,
                'message'         => 'Hay varios productos que coinciden. Pregunta al usuario cuál.',
                'options'         => $matches->take(6)->map(fn ($p) => [
                    'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku,
                    'price' => number_format($p->selling_price / 100, 2),
                ])->values()->toArray(),
            ];
        }

        $product = $matches->first();
        $qty = max(1, (int) ($input['quantity'] ?? 1));

        // Precio: unit_price directo, o total_price / qty, o null (lista).
        $unitPriceCents = null;
        if (isset($input['unit_price'])) {
            $unitPriceCents = (int) round(((float) $input['unit_price']) * 100);
        } elseif (isset($input['total_price'])) {
            $unitPriceCents = (int) round(((float) $input['total_price']) * 100 / $qty);
        }

        $discountCents = isset($input['discount']) ? (int) round(((float) $input['discount']) * 100) : 0;
        $method = (($input['payment_method'] ?? 'cash') === 'transfer') ? PaymentMethod::TRANSFER : PaymentMethod::CASH;

        try {
            $result = $this->quickSale->sell($product, $qty, $unitPriceCents, $method, $discountCents, $context->user->id);
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }

        $sale = $result['sale'];

        return [
            'ok'            => true,
            'sale_id'       => $sale->id,
            'invoice'       => $sale->invoice_number,
            'product'       => $product->name,
            'quantity'      => $qty,
            'total_bs'      => number_format($sale->total / 100, 2),
            'payment'       => $method->value,
            'below_cost'    => $result['below_cost'],
            'instructions'  => 'Confirma la venta al usuario con el desglose. '
                . ($result['below_cost'] ? 'Advierte ⚠️ que se vendió por debajo del costo. ' : '')
                . 'Recuérdale que puede escribir /deshacer para anular.',
        ];
    }
}
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/Sales/SellProductToolTest.php`:

```php
<?php

namespace Tests\Feature\Sales;

use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Agent\AgentContext;
use App\Services\Agent\Tools\SellProductTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellProductToolTest extends TestCase
{
    use RefreshDatabase;

    private SellProductTool $tool;
    private AgentContext $context;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = app(SellProductTool::class);
        $seller = User::factory()->staff()->create();
        $this->context = new AgentContext($seller, '555', 'telegram');

        $warehouse = Warehouse::create(['name' => 'Almacén', 'code' => 'ALM']);
        $this->location = Location::create(['name' => 'Estante', 'code' => 'EST', 'warehouse_id' => $warehouse->id]);
    }

    private function stockedProduct(string $name, int $selling, int $purchase, int $stock): Product
    {
        $p = Product::factory()->create([
            'name' => $name, 'selling_price' => $selling, 'purchase_price' => $purchase, 'quantity' => 0, 'is_active' => true,
        ]);
        ProductStock::create(['product_id' => $p->id, 'location_id' => $this->location->id, 'quantity' => $stock]);
        $p->update(['quantity' => $stock]);
        return $p;
    }

    public function test_is_not_web_exposed(): void
    {
        $this->assertFalse($this->tool->webExposed());
    }

    public function test_sells_with_unit_price_override(): void
    {
        $this->stockedProduct('Cable Huawei V8', 2000, 1800, 10);

        $result = $this->tool->execute([
            'product' => 'Cable Huawei V8', 'quantity' => 3, 'unit_price' => 15,
        ], $this->context);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['below_cost']); // 15 < 18
        $sale = Sale::find($result['sale_id']);
        $this->assertSame(4500, $sale->total); // 3 × 1500
    }

    public function test_total_price_is_divided_per_unit(): void
    {
        $this->stockedProduct('Funda A01', 3000, 1000, 10);

        $result = $this->tool->execute([
            'product' => 'Funda A01', 'quantity' => 2, 'total_price' => 50,
        ], $this->context);

        $this->assertTrue($result['ok']);
        $this->assertSame('50.00', $result['total_bs']); // 2 × 2500
    }

    public function test_returns_needs_selection_when_ambiguous(): void
    {
        $this->stockedProduct('Cargador Samsung 25w', 5000, 3000, 5);
        $this->stockedProduct('Cargador Samsung 45w', 8000, 5000, 5);

        $result = $this->tool->execute(['product' => 'Cargador Samsung'], $this->context);

        $this->assertTrue($result['needs_selection'] ?? false);
        $this->assertGreaterThan(1, count($result['options']));
    }

    public function test_returns_error_when_not_found(): void
    {
        $result = $this->tool->execute(['product' => 'ProductoInexistenteXYZ'], $this->context);

        $this->assertArrayHasKey('error', $result);
    }
}
```

- [ ] **Step 3: Run test to verify it fails, then register the tool**

Run: `php artisan test tests/Feature/Sales/SellProductToolTest.php`
Expected: primero FAIL si no compila; tras crear el tool, los tests de venta pasan pero el registro aún no importa para el test (usa `app(SellProductTool::class)` directo).

Registrar en `app/Providers/AppServiceProvider.php`, dentro del singleton `ToolRegistry`, después de la última línea `$registry->register(...)` de tools de venta/producto:

```php
            $registry->register($app->make(\App\Services\Agent\Tools\SellProductTool::class));
```

(`webExposed()=false` hace que el filtro del asistente web lo excluya, igual que `StartSaleTool`.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Sales/SellProductToolTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Agent/Tools/SellProductTool.php app/Providers/AppServiceProvider.php tests/Feature/Sales/SellProductToolTest.php
git commit -m "feat(sales): SellProductTool NL (producto+cantidad+precio negociado)"
```

---

## Task 4: Deshacer — CancelLastSaleTool + comando /deshacer

**Files:**
- Create: `app/Services/Agent/Tools/CancelLastSaleTool.php`
- Modify: `app/Providers/AppServiceProvider.php` (registrar)
- Modify: `app/Services/Telegram/BotHandler.php` (comando `/deshacer`)
- Test: `tests/Feature/Sales/CancelLastSaleToolTest.php`

- [ ] **Step 1: Write the tool**

`app/Services/Agent/Tools/CancelLastSaleTool.php`:

```php
<?php

namespace App\Services\Agent\Tools;

use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;
use App\Services\QuickSaleService;

class CancelLastSaleTool extends BaseTool
{
    public function __construct(private QuickSaleService $quickSale) {}

    public function webExposed(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'cancel_last_sale';
    }

    public function description(): string
    {
        return 'Anula (deshace) la última venta del usuario. Úsalo cuando pida "anula/deshaz la última venta" o "me equivoqué en la venta".';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function execute(array $input, AgentContext $context): array
    {
        if (! $context->user) {
            return ['error' => 'No hay usuario autenticado.'];
        }

        try {
            $sale = $this->quickSale->voidLast($context->user);
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }

        return [
            'ok'       => true,
            'sale_id'  => $sale->id,
            'invoice'  => $sale->invoice_number,
            'message'  => "Venta {$sale->invoice_number} anulada y stock restaurado.",
        ];
    }
}
```

- [ ] **Step 2: Write the failing test**

`tests/Feature/Sales/CancelLastSaleToolTest.php`:

```php
<?php

namespace Tests\Feature\Sales;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Agent\AgentContext;
use App\Services\Agent\Tools\CancelLastSaleTool;
use App\Services\QuickSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelLastSaleToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancels_last_sale_and_restores_stock(): void
    {
        $seller = User::factory()->staff()->create();
        $warehouse = Warehouse::create(['name' => 'Almacén', 'code' => 'ALM']);
        $location = Location::create(['name' => 'Estante', 'code' => 'EST', 'warehouse_id' => $warehouse->id]);
        $product = Product::factory()->create(['selling_price' => 2000, 'purchase_price' => 1000, 'quantity' => 0]);
        ProductStock::create(['product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 10]);
        $product->update(['quantity' => 10]);

        app(QuickSaleService::class)->sell($product, 4, null, PaymentMethod::CASH, 0, $seller->id);
        $this->assertSame(6, $product->fresh()->quantity);

        $tool = app(CancelLastSaleTool::class);
        $result = $tool->execute([], new AgentContext($seller, '555', 'telegram'));

        $this->assertTrue($result['ok']);
        $this->assertSame(10, $product->fresh()->quantity); // stock restaurado
    }

    public function test_error_when_no_recent_sale(): void
    {
        $seller = User::factory()->staff()->create();
        $tool = app(CancelLastSaleTool::class);

        $result = $tool->execute([], new AgentContext($seller, '555', 'telegram'));

        $this->assertArrayHasKey('error', $result);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test tests/Feature/Sales/CancelLastSaleToolTest.php`
Expected: FAIL — class not found.

- [ ] **Step 4: Register the tool + wire the /deshacer command**

En `app/Providers/AppServiceProvider.php`, junto a `SellProductTool`:

```php
            $registry->register($app->make(\App\Services\Agent\Tools\CancelLastSaleTool::class));
```

En `app/Services/Telegram/BotHandler.php`, en el `match ($command)` de `handleCommand()`, agregar un caso:

```php
            '/deshacer' => $this->cmdDeshacer($chatId),
```

Y agregar el método (usa el `QuickSaleService` vía `app()` para no tocar el constructor):

```php
    protected function cmdDeshacer(string $chatId): void
    {
        $user = $this->authHandler->getAuthenticatedUser($chatId);
        if (! $user) {
            $this->telegram->sendMessage($chatId, "❌ Sesión no válida.");
            return;
        }

        try {
            $sale = app(\App\Services\QuickSaleService::class)->voidLast($user);
            $this->telegram->sendMessage($chatId, "↩️ Venta <b>{$sale->invoice_number}</b> anulada. Stock restaurado.");
        } catch (\RuntimeException $e) {
            $this->telegram->sendMessage($chatId, "❌ " . $e->getMessage());
        }
    }
```

Y en `cmdHelp()`, agregar al texto de ayuda (después de `/devolver`):

```php
            "/deshacer — Anular tu última venta\n" .
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Sales/CancelLastSaleToolTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Agent/Tools/CancelLastSaleTool.php app/Providers/AppServiceProvider.php app/Services/Telegram/BotHandler.php tests/Feature/Sales/CancelLastSaleToolTest.php
git commit -m "feat(sales): deshacer venta (/deshacer + cancel_last_sale NL)"
```

---

## Cierre de Fase 1

- [ ] **Suite completa**

Run: `php artisan test`
Expected: verde (sin regresiones).

- [ ] **Smoke-test del bot real**

Con el bot: "vender [producto] 3 unidades a 15 bs" → confirma venta + desglose + aviso bajo costo si aplica. Luego `/deshacer` → venta anulada + stock restaurado. Verificar en el panel que la venta quedó `cancelled` y el stock volvió.

- [ ] **Code review** con `superpowers:requesting-code-review` sobre el branch `feature/fast-sales`.

---

## Cobertura del spec (self-review)

| Requisito | Task |
|-----------|------|
| R1 tool `sell_product` NL, `webExposed=false` | Task 3 |
| R2 interpretación precio (a X / en total X / lista) | Task 3 (unit_price vs total_price) |
| R3 producto ambiguo → preguntar | Task 3 (`needs_selection`) |
| R4 bajo costo → vende + ⚠️ + log actor | Task 1 (`below_cost`) + Task 3 (aviso) |
| R5 respuesta con desglose + # + pista deshacer | Task 3 (`instructions`) |
| R6 deshacer (ventana/propiedad/admin/reversa) | Task 2 (`void`/`voidLast`) + Task 4 (comando/tool) |
| R10 precio real + actor guardados | Task 1 (SaleData `unit_price` + `created_by`) |

**Fuera de esta fase (fases siguientes):** R7 "Cobrar rápido" web + toast (Fase 2), R11 foto comprobante transferencia (Fase 2), R8 botón "Vender" instantáneo + R9 flujo avanzado por palabra clave (Fase 3).
