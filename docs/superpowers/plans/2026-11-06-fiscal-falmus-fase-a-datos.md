# Facturación vía Falmus — Fase A (datos listos, cero llamadas) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dejar el inventario con todos los campos y la estructura que pide la API de Falmus (doc. "Falmus_API_Referencia.pdf", 2026-11-06) para que, cuando Falmus esté listo, conectar sea **agregar un adaptador**, no refactorizar. Esta fase no llama a ninguna API y no cambia el comportamiento de la venta.

**Architecture:** Se respetan las 3 costuras de la macro SIAT: (1) la venta nunca cambia, la factura cuelga de ella; (2) se diseña contra una interfaz, no contra Falmus; (3) la factura es un documento **derivado** (`invoices` 1:0..1 `sales`). Falmus resuelve CUIS/CUFD/XML/CUF/firma, así que la ruta "SIAT directo" de F1a (`FiscalProvider`, ciclo CUIS/CUFD) queda **dormida, no se borra**, y F1b (XML/CUF/XSD propio) **ya no hace falta**. La venta se traduce a un `InvoiceDraft` neutral (centavos, descuentos por línea); el adaptador Falmus (Fase C) lo convierte a JSON.

**Tech Stack:** Laravel 11, PHPUnit (sqlite :memory:), Eloquent, `Setting` con claves cifradas.

---

## 0. Matriz de compatibilidad (resultado de la auditoría)

| Campo Falmus | Inventario hoy | Estado |
|---|---|---|
| `cliente.codigoTipoDocumento` | `customers.doc_type` (1-5) | ✅ |
| `cliente.numeroDocumento` | `customers.doc_number` | ✅ |
| `cliente.razonSocial` | `customers.business_name` (fallback `name`) | ✅ |
| `cliente.codigoCliente` | no existe → derivar `C-{customers.id}` | ✅ sin columna |
| complemento del CI | `customers.doc_complement` | ⚠️ Falmus no lo lista — **preguntar** |
| `detalles[].codigoProductoSin` | `products.sin_code` | ✅ (falta poblar) |
| `detalles[].codigoUnidadMedida` | `units.sin_code` | ✅ (falta poblar) |
| `detalles[].actividadEconomica` | **no existe** | ❌ → Task 1 |
| `detalles[].precioUnitario/cantidad` | `sale_items.unit_price` (centavos) / `quantity` (int) | ✅ convertir ÷100 |
| descuento por ítem | `sale_items.discount` (por unidad) | ⚠️ ver Pregunta 3 |
| descuento global | `sales.global_discount` | ⚠️ sector 1 no muestra `descuentoAdicional` — **preguntar** |
| `pago.codigoMetodoPago` | `PaymentMethod::siatCode()` | ⚠️ TRANSFER=1 provisional; validar contra catálogo `metodos_pago` (Fase B) |
| `puntoVentaId` (UUID) | no existe | ❌ → Task 3 (setting) |
| `X-API-Key` | no existe | ❌ → Task 3 (setting cifrado) |
| respuesta: id, cuf, nroFactura, estado, codigoEstado, urls | no existe | ❌ → Task 2 (`invoices`) |
| anulación (`codigoMotivo`) | `SaleService::cancelSale` (total) | ✅ se engancha en Fase D |
| nota de crédito (devolución parcial) | **no hay devoluciones parciales** | ⏳ fuera de alcance hasta que exista la función |
| catálogos SIAT | `fiscal_catalog_entries` (espejo) | ✅ reusar; `codigoActividad` va en `extra` |
| `sales.invoice_number` | es el correlativo **interno** del recibo | ⚠️ NO es el nro. fiscal; no tocar |

## 1. Preguntas para tu amigo (bloquean Fase C, no Fase A)

1. **Idempotencia / referencia externa.** `POST /facturas` no tiene `Idempotency-Key` y `/facturas/buscar` no filtra por una referencia nuestra. Si se corta la conexión no hay forma segura de saber si la venta X ya se facturó. Pedir: `Idempotency-Key` o un campo `referenciaExterna` buscable.
2. **Complemento del CI**: ¿dónde va (`cliente.complemento`)?
3. **Descuentos en sector 1**: ¿acepta `detalles[].montoDescuento` y `datosSector.descuentoAdicional`? Hoy los "campos desconocidos se rechazan".
4. **Venta sin datos del comprador**: ¿qué mandar (99002 / "S/N" / "SIN NOMBRE")? ¿Falmus soporta el **consolidado diario** (NIT 99003) o eso queda a nuestro cargo?
5. **Cantidades decimales** y redondeo: ¿cuántos decimales en `precioUnitario` y `cantidad`?
6. Los 9 puntos del cap. 11 §6 del PDF (permisos con/sin guion bajo, `/public` con o sin clave, `tipoSiat`, etc.).

## 2. Hoja de ruta completa (cada fase = su propio plan/branch)

| Fase | Qué | Llama a Falmus |
|---|---|---|
| **A (este plan)** | Campos, tabla `invoices`, settings, `InvoiceDraft`, chequeo de preparación | No |
| B | `FalmusClient` (HTTP, reintentos solo lecturas), sync de catálogos a `fiscal_catalog_entries`, pantalla para asignar actividad/producto SIN/unidad, validar `metodos_pago` | Sí (lecturas) |
| C | Interfaz `InvoiceGateway` + `SimulatorInvoiceGateway` + `FalmusInvoiceGateway`; job en cola `IssueInvoiceForSale` disparado **después del commit** de la venta (nunca HTTP dentro de la transacción), `Cache::lock` por venta, sin reintento automático de emisión; PDF/QR en el recibo | Sí |
| D | Anular al cancelar venta facturada (stock vuelve solo si Falmus confirma); job programado `sincronizar-estado` para `pendiente_envio`/`observado`; `fiscal:daily-cycle` en modo falmus salta CUIS/CUFD | Sí |
| E | Notas de crédito (requiere antes crear devoluciones parciales en el inventario) | Sí |

---

## File Structure (Fase A)

- Create: `database/migrations/2026_11_06_100000_add_economic_activity_to_products.php` — columna `economic_activity`.
- Create: `database/migrations/2026_11_06_100100_create_invoices_table.php` — documento fiscal derivado.
- Create: `app/Enums/InvoiceStatus.php` — máquina de estados fiscal (mapea estados Falmus).
- Create: `app/Models/Invoice.php`
- Modify: `app/Models/Sale.php` — relación `invoice()`.
- Modify: `app/Models/Product.php` — `economic_activity` en `$fillable`.
- Modify: `app/Models/Setting.php` — `falmus_api_key` en `ENCRYPTED_KEYS`.
- Create: `app/Fiscal/Invoicing/InvoiceDraft.php`, `InvoiceDraftLine.php`, `InvoiceBuyer.php` — DTOs neutrales.
- Create: `app/Fiscal/Invoicing/InvoiceDraftFactory.php` — `Sale` → `InvoiceDraft`.
- Create: `app/Fiscal/Invoicing/FiscalReadiness.php` — lista lo que falta para poder facturar.
- Create: `app/Console/Commands/FiscalReadinessCommand.php` — `php artisan fiscal:readiness`.
- Tests: `tests/Feature/Fiscal/Invoicing/*.php`

**Precondición:** partir de `main` **después** de mergear `hardening/fase-0` (ese branch modifica `Setting.php` y `SettingsCatalog.php`). Branch: `feature/fiscal-falmus-fase-a`.

**Deploy:** solo `php artisan migrate` (aditiva). **NUNCA** `migrate:fresh` en MySQL de dev/prod.

---

### Task 1: Actividad económica en productos

**Files:**
- Create: `database/migrations/2026_11_06_100000_add_economic_activity_to_products.php`
- Modify: `app/Models/Product.php` (`$fillable`)
- Test: `tests/Feature/Fiscal/Invoicing/ProductEconomicActivityTest.php`

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Fiscal\Invoicing;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductEconomicActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_stores_economic_activity(): void
    {
        $p = Product::factory()->create(['economic_activity' => '477300']);

        $this->assertSame('477300', $p->fresh()->economic_activity);
    }

    public function test_economic_activity_is_optional(): void
    {
        $p = Product::factory()->create();

        $this->assertNull($p->fresh()->economic_activity);
    }
}
```

- [ ] **Step 2: Correr y ver fallar**

Run: `php artisan test tests/Feature/Fiscal/Invoicing/ProductEconomicActivityTest.php`
Expected: FAIL (`no such column: economic_activity`).

- [ ] **Step 3: Migración**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Código de actividad económica SIN (catálogo "actividades"). Nullable: si falta,
    // se usa el setting fiscal_default_activity (la mayoría de negocios tiene una sola).
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->string('economic_activity', 20)->nullable()->after('sin_code'));
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('economic_activity'));
    }
};
```

En `app/Models/Product.php`, agregar `'economic_activity',` justo después de `'sin_code',` en `$fillable`.

- [ ] **Step 4: Correr y ver pasar**

Run: `php artisan test tests/Feature/Fiscal/Invoicing/ProductEconomicActivityTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_11_06_100000_add_economic_activity_to_products.php app/Models/Product.php tests/Feature/Fiscal/Invoicing/ProductEconomicActivityTest.php
git commit -m "feat(fiscal): actividad económica SIN por producto"
```

---

### Task 2: Tabla `invoices` (documento fiscal derivado) + estados

**Files:**
- Create: `database/migrations/2026_11_06_100100_create_invoices_table.php`
- Create: `app/Enums/InvoiceStatus.php`
- Create: `app/Models/Invoice.php`
- Modify: `app/Models/Sale.php` (relación)
- Test: `tests/Feature/Fiscal/Invoicing/InvoiceModelTest.php`

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Fiscal\Invoicing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Sale;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_has_at_most_one_invoice(): void
    {
        $sale = Sale::factory()->create();
        $inv = Invoice::create(['sale_id' => $sale->id, 'provider' => 'simulator', 'status' => InvoiceStatus::DRAFT, 'total' => 1000]);

        $this->assertTrue($sale->invoice->is($inv));

        $this->expectException(QueryException::class);
        Invoice::create(['sale_id' => $sale->id, 'provider' => 'simulator', 'status' => InvoiceStatus::DRAFT, 'total' => 1000]);
    }

    public function test_maps_falmus_states(): void
    {
        $this->assertSame(InvoiceStatus::ACCEPTED, InvoiceStatus::fromProvider('aceptado'));
        $this->assertSame(InvoiceStatus::PENDING_SEND, InvoiceStatus::fromProvider('pendiente_envio'));
        $this->assertSame(InvoiceStatus::VOIDED, InvoiceStatus::fromProvider('anulado'));
        $this->assertSame(InvoiceStatus::NEEDS_REVIEW, InvoiceStatus::fromProvider('requiere_revision'));
        $this->assertSame(InvoiceStatus::NEEDS_REVIEW, InvoiceStatus::fromProvider('algo_nuevo'));
    }

    public function test_only_final_states_block_reissue(): void
    {
        $this->assertTrue(InvoiceStatus::ACCEPTED->blocksReissue());
        $this->assertTrue(InvoiceStatus::PENDING_SEND->blocksReissue());
        $this->assertTrue(InvoiceStatus::UNCERTAIN->blocksReissue());
        $this->assertFalse(InvoiceStatus::FAILED->blocksReissue());
        $this->assertFalse(InvoiceStatus::DRAFT->blocksReissue());
    }
}
```

- [ ] **Step 2: Correr y ver fallar**

Run: `php artisan test tests/Feature/Fiscal/Invoicing/InvoiceModelTest.php`
Expected: FAIL (`Class "App\Enums\InvoiceStatus" not found`).

- [ ] **Step 3: Enum**

```php
<?php

namespace App\Enums;

/**
 * Estado fiscal de una factura. Independiente del proveedor: fromProvider() traduce
 * los textos de Falmus. Un estado desconocido cae en NEEDS_REVIEW (nunca se asume OK).
 */
enum InvoiceStatus: string
{
    case DRAFT = 'draft';               // creada localmente, aún no enviada
    case ISSUING = 'issuing';           // request en vuelo
    case ACCEPTED = 'accepted';         // 908
    case OBSERVED = 'observed';         // 904
    case REJECTED = 'rejected';         // 902
    case VOIDED = 'voided';             // 905
    case PENDING_SEND = 'pending_send'; // contingencia: Falmus la enviará solo
    case UNCERTAIN = 'uncertain';       // se cortó la respuesta: NO reemitir, conciliar
    case NEEDS_REVIEW = 'needs_review'; // estado raro del proveedor
    case FAILED = 'failed';             // 4xx de validación: se puede corregir y reintentar

    public static function fromProvider(string $estado): self
    {
        return match ($estado) {
            'aceptado' => self::ACCEPTED,
            'observado' => self::OBSERVED,
            'rechazado' => self::REJECTED,
            'anulado' => self::VOIDED,
            'pendiente_envio', 'pendiente_validacion' => self::PENDING_SEND,
            default => self::NEEDS_REVIEW,
        };
    }

    /** true = ya existe (o puede existir) una factura en el SIN: prohibido emitir otra. */
    public function blocksReissue(): bool
    {
        return ! in_array($this, [self::DRAFT, self::FAILED, self::REJECTED], true);
    }
}
```

- [ ] **Step 4: Migración**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->unique()->constrained()->restrictOnDelete();
            $t->string('provider', 20);                       // falmus | simulator
            $t->string('status', 20)->index();
            $t->unsignedSmallInteger('document_sector')->default(1);
            $t->string('point_of_sale_ref')->nullable();      // puntoVentaId (UUID) usado
            // Respuesta del proveedor
            $t->uuid('external_id')->nullable()->unique();    // facturaId de Falmus
            $t->string('cuf')->nullable()->index();
            $t->unsignedBigInteger('number')->nullable();     // nroFactura fiscal
            $t->unsignedSmallInteger('siat_status_code')->nullable();
            $t->string('url_pdf')->nullable();
            $t->string('url_xml')->nullable();
            $t->text('url_qr')->nullable();
            $t->timestamp('issued_at')->nullable();
            // Foto del comprador al momento de facturar (el cliente puede cambiar después)
            $t->string('buyer_doc_type', 4)->nullable();
            $t->string('buyer_doc_number', 30)->nullable();
            $t->string('buyer_doc_complement', 10)->nullable();
            $t->string('buyer_name')->nullable();
            $t->string('buyer_code', 40)->nullable();
            $t->bigInteger('total');                          // centavos
            // Anulación
            $t->unsignedSmallInteger('void_reason_code')->nullable();
            $t->timestamp('voided_at')->nullable();
            // Diagnóstico
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->json('last_error')->nullable();               // problem+json, incluye traceId
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
```

- [ ] **Step 5: Modelo + relación**

`app/Models/Invoice.php`:

```php
<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'status' => InvoiceStatus::class,
        'total' => 'integer',
        'issued_at' => 'datetime',
        'voided_at' => 'datetime',
        'last_error' => 'array',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
```

En `app/Models/Sale.php` agregar (y `use Illuminate\Database\Eloquent\Relations\HasOne;` si no está):

```php
    /** Factura fiscal derivada (0..1). La venta no depende de ella. */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }
```

- [ ] **Step 6: Correr y ver pasar**

Run: `php artisan test tests/Feature/Fiscal/Invoicing/InvoiceModelTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_11_06_100100_create_invoices_table.php app/Enums/InvoiceStatus.php app/Models/Invoice.php app/Models/Sale.php tests/Feature/Fiscal/Invoicing/InvoiceModelTest.php
git commit -m "feat(fiscal): tabla invoices derivada de la venta y estados fiscales"
```

---

### Task 3: Settings de conexión (clave cifrada)

**Files:**
- Modify: `app/Models/Setting.php` (`ENCRYPTED_KEYS`)
- Test: `tests/Feature/Fiscal/Invoicing/FalmusSettingsTest.php`

Settings nuevos (sin UI todavía — la UI va en Fase B dentro de Ajustes con ability `finance.accounting`):
`falmus_api_url` (default `https://api.falmus.com/api/v1`), `falmus_api_key` (**cifrado**), `falmus_point_of_sale_id`, `fiscal_default_activity`. `fiscal_provider` acepta además el valor `falmus` (el binding se cambia en Fase C).

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Fiscal\Invoicing;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FalmusSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_key_is_encrypted_at_rest(): void
    {
        Setting::set('falmus_api_key', 'flm_test_abc123');

        $raw = DB::table('settings')->where('key', 'falmus_api_key')->value('value');

        $this->assertNotSame('flm_test_abc123', $raw);
        $this->assertStringNotContainsString('flm_test_', (string) $raw);
        $this->assertSame('flm_test_abc123', Setting::get('falmus_api_key'));
    }
}
```

- [ ] **Step 2: Correr y ver fallar**

Run: `php artisan test tests/Feature/Fiscal/Invoicing/FalmusSettingsTest.php`
Expected: FAIL (el valor queda en texto plano).

- [ ] **Step 3: Implementar** — en `app/Models/Setting.php`, agregar `'falmus_api_key',` a `ENCRYPTED_KEYS` (después de `'siat_credential',`).

- [ ] **Step 4: Correr y ver pasar**

Run: `php artisan test tests/Feature/Fiscal/Invoicing/FalmusSettingsTest.php tests/Feature/Settings/SettingSecretCacheTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Setting.php tests/Feature/Fiscal/Invoicing/FalmusSettingsTest.php
git commit -m "feat(fiscal): clave API de Falmus cifrada en settings"
```

---

### Task 4: `InvoiceDraft` neutral desde una venta

Regla de montos: `Σ (unitPrice × quantity − discount) == sale.total`. El descuento por ítem (`sale_items.discount`, por unidad) y la parte proporcional del descuento global se juntan en `discount` de la línea; el resto del prorrateo (centavos sobrantes) va a la última línea. Así el adaptador decide después si lo manda como `montoDescuento`, `descuentoAdicional` o lo pliega al precio (Pregunta 3), sin tocar esta clase.

**Files:**
- Create: `app/Fiscal/Invoicing/InvoiceBuyer.php`, `InvoiceDraftLine.php`, `InvoiceDraft.php`, `InvoiceDraftFactory.php`
- Test: `tests/Feature/Fiscal/Invoicing/InvoiceDraftFactoryTest.php`

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Fiscal\Invoicing;

use App\Enums\PaymentMethod;
use App\Fiscal\Invoicing\InvoiceDraftFactory;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceDraftFactoryTest extends TestCase
{
    use RefreshDatabase;

    private function saleWith(array $lines, int $globalDiscount = 0, ?Customer $customer = null): Sale
    {
        $subtotal = 0;
        foreach ($lines as [$p, $qty, $unit, $disc]) {
            $subtotal += ($unit - $disc) * $qty;
        }
        $sale = Sale::factory()->create([
            'customer_id' => $customer?->id,
            'subtotal' => $subtotal,
            'global_discount' => $globalDiscount,
            'total' => $subtotal - $globalDiscount,
            'payment_method' => PaymentMethod::CARD,
        ]);
        foreach ($lines as [$p, $qty, $unit, $disc]) {
            SaleItem::create([
                'sale_id' => $sale->id, 'product_id' => $p->id, 'quantity' => $qty,
                'cost_price' => 0, 'unit_price' => $unit, 'discount' => $disc,
                'final_price' => $unit - $disc, 'subtotal' => ($unit - $disc) * $qty,
            ]);
        }

        return $sale->fresh('items.product.unit', 'customer');
    }

    public function test_lines_carry_sin_codes_and_amounts_add_up_with_global_discount(): void
    {
        Setting::set('fiscal_default_activity', '477300');
        $unit = Unit::factory()->create(['sin_code' => '58']);
        $a = Product::factory()->create(['name' => 'Arroz', 'sin_code' => '1001', 'unit_id' => $unit->id]);
        $b = Product::factory()->create(['name' => 'Azúcar', 'sin_code' => '1002', 'unit_id' => $unit->id, 'economic_activity' => '461000']);

        // 3 × 10.00 con 1.00 desc/u  +  1 × 5.00 ; global 1.00 → total 31.00
        $sale = $this->saleWith([[$a, 3, 1000, 100], [$b, 1, 500, 0]], 100);

        $draft = app(InvoiceDraftFactory::class)->fromSale($sale);

        $this->assertSame(1, $draft->documentSector);
        $this->assertSame(2, $draft->paymentMethodCode); // CARD
        $this->assertCount(2, $draft->lines);
        $this->assertSame('477300', $draft->lines[0]->economicActivity); // fallback setting
        $this->assertSame('461000', $draft->lines[1]->economicActivity);
        $this->assertSame('1001', $draft->lines[0]->sinProductCode);
        $this->assertSame('58', $draft->lines[0]->sinUnitCode);
        $this->assertSame(1000, $draft->lines[0]->unitPrice);

        $sum = array_sum(array_map(fn ($l) => $l->unitPrice * $l->quantity - $l->discount, $draft->lines));
        $this->assertSame($sale->total, $sum);
        $this->assertSame($sale->total, $draft->total);
    }

    public function test_global_discount_remainder_goes_to_last_line(): void
    {
        $p = Product::factory()->create();
        $sale = $this->saleWith([[$p, 1, 1000, 0], [$p, 1, 1000, 0], [$p, 1, 1000, 0]], 100); // 100/3

        $draft = app(InvoiceDraftFactory::class)->fromSale($sale);

        $this->assertSame([33, 33, 34], array_map(fn ($l) => $l->discount, $draft->lines));
    }

    public function test_buyer_snapshot_from_customer_identity(): void
    {
        $c = Customer::factory()->create([
            'name' => 'Juan', 'doc_type' => '1', 'doc_number' => '1234567',
            'doc_complement' => '1A', 'business_name' => 'JUAN PEREZ',
        ]);
        $p = Product::factory()->create();
        $draft = app(InvoiceDraftFactory::class)->fromSale($this->saleWith([[$p, 1, 1000, 0]], 0, $c));

        $this->assertSame('1', $draft->buyer->docType);
        $this->assertSame('1234567', $draft->buyer->docNumber);
        $this->assertSame('1A', $draft->buyer->complement);
        $this->assertSame('JUAN PEREZ', $draft->buyer->name);
        $this->assertSame('C-'.$c->id, $draft->buyer->code);
    }

    public function test_sale_without_identity_has_null_buyer(): void
    {
        $p = Product::factory()->create();
        $draft = app(InvoiceDraftFactory::class)->fromSale($this->saleWith([[$p, 1, 1000, 0]]));

        $this->assertNull($draft->buyer);
    }
}
```

> Si `UnitFactory` no acepta `sin_code` o `SaleFactory` exige otros campos, ajustar solo el arreglo de atributos del test — no la lógica.

- [ ] **Step 2: Correr y ver fallar**

Run: `php artisan test tests/Feature/Fiscal/Invoicing/InvoiceDraftFactoryTest.php`
Expected: FAIL (`Class "App\Fiscal\Invoicing\InvoiceDraftFactory" not found`).

- [ ] **Step 3: DTOs**

`app/Fiscal/Invoicing/InvoiceBuyer.php`:

```php
<?php

namespace App\Fiscal\Invoicing;

readonly class InvoiceBuyer
{
    public function __construct(
        public string $docType,
        public string $docNumber,
        public ?string $complement,
        public string $name,
        public ?string $code,
    ) {}
}
```

`app/Fiscal/Invoicing/InvoiceDraftLine.php`:

```php
<?php

namespace App\Fiscal\Invoicing;

/** Montos en centavos. discount = descuento TOTAL de la línea (ítem × cantidad + parte del global). */
readonly class InvoiceDraftLine
{
    public function __construct(
        public ?int $productId,
        public string $description,
        public int $quantity,
        public int $unitPrice,
        public int $discount,
        public ?string $economicActivity,
        public ?string $sinProductCode,
        public ?string $sinUnitCode,
    ) {}

    public function net(): int
    {
        return $this->unitPrice * $this->quantity - $this->discount;
    }
}
```

`app/Fiscal/Invoicing/InvoiceDraft.php`:

```php
<?php

namespace App\Fiscal\Invoicing;

/** Factura lista para cualquier proveedor. No sabe nada de Falmus. Montos en centavos. */
readonly class InvoiceDraft
{
    /** @param list<InvoiceDraftLine> $lines */
    public function __construct(
        public int $saleId,
        public int $documentSector,
        public ?InvoiceBuyer $buyer,
        public int $paymentMethodCode,
        public array $lines,
        public int $total,
    ) {}
}
```

- [ ] **Step 4: Factory**

`app/Fiscal/Invoicing/InvoiceDraftFactory.php`:

```php
<?php

namespace App\Fiscal\Invoicing;

use App\Models\Sale;
use App\Models\Setting;

class InvoiceDraftFactory
{
    public function fromSale(Sale $sale): InvoiceDraft
    {
        $sale->loadMissing('items.product.unit', 'customer');
        $defaultActivity = Setting::get('fiscal_default_activity') ?: null;

        $items = $sale->items->values();
        $lineNets = $items->map(fn ($i) => (int) $i->final_price * (int) $i->quantity)->all();
        $globalShares = $this->prorate((int) $sale->global_discount, $lineNets);

        $lines = [];
        foreach ($items as $idx => $item) {
            $p = $item->product;
            $lines[] = new InvoiceDraftLine(
                productId: $item->product_id,
                description: $p?->name ?? 'Producto',
                quantity: (int) $item->quantity,
                unitPrice: (int) $item->unit_price,
                discount: (int) $item->discount * (int) $item->quantity + $globalShares[$idx],
                economicActivity: $p?->economic_activity ?: $defaultActivity,
                sinProductCode: $p?->sin_code ?: null,
                sinUnitCode: $p?->unit?->sin_code ?: null,
            );
        }

        return new InvoiceDraft(
            saleId: $sale->id,
            documentSector: 1,
            buyer: $this->buyer($sale),
            paymentMethodCode: $sale->payment_method->siatCode(),
            lines: $lines,
            total: (int) $sale->total,
        );
    }

    /**
     * Reparte $amount proporcional a $weights; el sobrante por redondeo va a la última línea.
     *
     * @param list<int> $weights
     * @return list<int>
     */
    private function prorate(int $amount, array $weights): array
    {
        $n = count($weights);
        $sum = array_sum($weights);
        if ($amount === 0 || $n === 0 || $sum === 0) {
            return array_fill(0, $n, 0);
        }
        $shares = [];
        $assigned = 0;
        foreach ($weights as $i => $w) {
            $share = $i === $n - 1 ? $amount - $assigned : intdiv($amount * $w, $sum);
            $shares[] = $share;
            $assigned += $share;
        }

        return $shares;
    }

    private function buyer(Sale $sale): ?InvoiceBuyer
    {
        $c = $sale->customer;
        if (! $c || ! $c->hasBillingIdentity()) {
            return null;
        }
        $id = $c->billingIdentity();

        return new InvoiceBuyer(
            docType: $id->docType,
            docNumber: $id->docNumber,
            complement: $id->complement ?: null,
            name: $id->businessName ?: $c->name,
            code: 'C-'.$c->id,
        );
    }
}
```

- [ ] **Step 5: Correr y ver pasar**

Run: `php artisan test tests/Feature/Fiscal/Invoicing/InvoiceDraftFactoryTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Fiscal/Invoicing tests/Feature/Fiscal/Invoicing/InvoiceDraftFactoryTest.php
git commit -m "feat(fiscal): InvoiceDraft neutral desde la venta con prorrateo de descuentos"
```

---

### Task 5: Chequeo de preparación (`fiscal:readiness`)

Dice qué falta para poder facturar, sin llamar a nadie. Sirve para ir completando datos mientras Falmus termina.

**Files:**
- Create: `app/Fiscal/Invoicing/FiscalReadiness.php`
- Create: `app/Console/Commands/FiscalReadinessCommand.php`
- Test: `tests/Feature/Fiscal/Invoicing/FiscalReadinessTest.php`

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Fiscal\Invoicing;

use App\Fiscal\Invoicing\FiscalReadiness;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_missing_config_and_product_codes(): void
    {
        $unitOk = Unit::factory()->create(['sin_code' => '58']);
        $unitNo = Unit::factory()->create(['sin_code' => null]);
        Product::factory()->create(['sin_code' => '1001', 'unit_id' => $unitOk->id]);
        $bad = Product::factory()->create(['sin_code' => null, 'unit_id' => $unitNo->id]);

        $r = app(FiscalReadiness::class)->report();

        $this->assertContains('falmus_api_key', $r['missing_settings']);
        $this->assertContains('falmus_point_of_sale_id', $r['missing_settings']);
        $this->assertContains('fiscal_default_activity', $r['missing_settings']);
        $this->assertSame([$bad->id], $r['products_missing_sin_code']);
        $this->assertSame([$unitNo->id], $r['units_missing_sin_code']);
        $this->assertFalse($r['ready']);
    }

    public function test_ready_when_everything_is_set(): void
    {
        Setting::set('falmus_api_key', 'flm_test_x');
        Setting::set('falmus_point_of_sale_id', '00000000-0000-4000-8000-000000000022');
        Setting::set('fiscal_default_activity', '477300');
        $u = Unit::factory()->create(['sin_code' => '58']);
        Product::factory()->create(['sin_code' => '1001', 'unit_id' => $u->id]);

        $this->assertTrue(app(FiscalReadiness::class)->report()['ready']);
        $this->artisan('fiscal:readiness')->assertSuccessful();
    }
}
```

- [ ] **Step 2: Correr y ver fallar**

Run: `php artisan test tests/Feature/Fiscal/Invoicing/FiscalReadinessTest.php`
Expected: FAIL (clase no existe).

- [ ] **Step 3: Servicio**

```php
<?php

namespace App\Fiscal\Invoicing;

use App\Models\Product;
use App\Models\Setting;
use App\Models\Unit;

/** Qué falta para poder emitir. Solo lectura, sin red. */
class FiscalReadiness
{
    private const REQUIRED_SETTINGS = ['falmus_api_key', 'falmus_point_of_sale_id', 'fiscal_default_activity'];

    /** @return array{ready:bool, missing_settings:list<string>, products_missing_sin_code:list<int>, units_missing_sin_code:list<int>} */
    public function report(): array
    {
        $missingSettings = array_values(array_filter(
            self::REQUIRED_SETTINGS,
            fn ($k) => trim((string) Setting::get($k, '')) === ''
        ));

        $products = Product::query()
            ->where(fn ($q) => $q->whereNull('sin_code')->orWhere('sin_code', ''))
            ->orderBy('id')->pluck('id')->all();

        $units = Unit::query()
            ->whereIn('id', Product::query()->whereNotNull('unit_id')->select('unit_id'))
            ->where(fn ($q) => $q->whereNull('sin_code')->orWhere('sin_code', ''))
            ->orderBy('id')->pluck('id')->all();

        return [
            'ready' => $missingSettings === [] && $products === [] && $units === [],
            'missing_settings' => $missingSettings,
            'products_missing_sin_code' => $products,
            'units_missing_sin_code' => $units,
        ];
    }
}
```

- [ ] **Step 4: Comando**

```php
<?php

namespace App\Console\Commands;

use App\Fiscal\Invoicing\FiscalReadiness;
use Illuminate\Console\Command;

class FiscalReadinessCommand extends Command
{
    protected $signature = 'fiscal:readiness';

    protected $description = 'Lista qué datos faltan para facturar (settings, códigos SIN de productos y unidades)';

    public function handle(FiscalReadiness $readiness): int
    {
        $r = $readiness->report();

        $this->line($r['ready'] ? '<info>Listo para facturar.</info>' : '<comment>Faltan datos:</comment>');
        foreach ($r['missing_settings'] as $k) {
            $this->line("  - setting: {$k}");
        }
        if ($r['products_missing_sin_code']) {
            $this->line('  - productos sin código SIN: '.count($r['products_missing_sin_code']).' (ids '.implode(',', array_slice($r['products_missing_sin_code'], 0, 20)).')');
        }
        if ($r['units_missing_sin_code']) {
            $this->line('  - unidades sin código SIN: ids '.implode(',', $r['units_missing_sin_code']));
        }

        return self::SUCCESS; // informativo: no falla el deploy
    }
}
```

- [ ] **Step 5: Correr y ver pasar**

Run: `php artisan test tests/Feature/Fiscal/Invoicing/FiscalReadinessTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Suite completa (nada se rompió)**

Run: `php artisan test`
Expected: misma cantidad de fallos que en `main` antes de empezar (idealmente 0). La venta, POS, bot y contabilidad no se tocaron.

- [ ] **Step 7: Commit**

```bash
git add app/Fiscal/Invoicing/FiscalReadiness.php app/Console/Commands/FiscalReadinessCommand.php tests/Feature/Fiscal/Invoicing/FiscalReadinessTest.php
git commit -m "feat(fiscal): comando fiscal:readiness para ver qué falta antes de facturar"
```

---

## Criterio de "Fase A terminada"

- `php artisan migrate` aditivo, sin tocar datos existentes.
- La venta rápida, el POS y `cancelSale` se comportan igual (suite verde).
- Cualquier venta se puede convertir en `InvoiceDraft` cuyos montos cuadran al centavo con `sales.total`.
- `php artisan fiscal:readiness` dice exactamente qué productos/unidades/settings completar.
- Las 6 preguntas de §1 enviadas a Falmus; sus respuestas definen el plan de Fase C.
