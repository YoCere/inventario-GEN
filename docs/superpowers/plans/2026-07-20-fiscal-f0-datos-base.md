# Datos fiscales base (F0) — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Capturar los datos que la facturación SIAT exige (identidad tributaria del cliente, códigos SIN de producto/unidad, desglose de impuestos por venta, método de pago QR) sin emitir facturas todavía y sin tocar el flujo de venta rápida.

**Architecture:** Todo aditivo. Migraciones nullable sobre `customers`/`products`/`units`/`sales`. Un objeto de valor `BillingIdentity` + métodos en `Customer` como núcleo reutilizable framework-agnóstico. Un `SaleTaxCalculator` aislado que `SaleService::createSale` invoca para persistir el desglose. El enum `PaymentMethod` gana QR/CARD + `siatCode()`. La UI (POS Alpine, formularios Livewire de cliente/producto/unidad) suma los campos, reusando el mismo núcleo.

**Tech Stack:** Laravel 11, Livewire 3, Alpine (POS), PHPUnit (class-style), MySQL. Sin dependencias nuevas.

**Spec:** `docs/superpowers/specs/2026-07-20-fiscal-f0-datos-base-design.md`

---

## Convenciones del repo (leer antes de empezar)

- **NUNCA `migrate:fresh`/`migrate:refresh`** (MySQL dev compartido). Migraciones aditivas.
- Dinero en **centavos** (enteros) en toda la capa de ventas.
- Tests: clase PHPUnit, `extends Tests\TestCase`, `use RefreshDatabase`. Correr `php artisan test --filter <Clase>`.
- Livewire auto-discovery en `App\Livewire`. Tests con `Livewire::test(...)`.
- El POS (`resources/views/sales/create.blade.php`) es **Alpine** (`x-data="pos()"`, ~800 líneas), postea a `SalesController@store` validado por `StoreSaleRequest`. NO es Livewire.
- Blade admin usa tokens del design system y componentes `<x-...>`. El POS usa Tailwind + Alpine + TomSelect.

## Estado actual (anclas reales)

- `App\Models\Sale` — fillable incluye `payment_method`, `subtotal`, `global_discount`, `total_discount`, `total`, `change`; casts a int; `SaleStatus`/`PaymentMethod` enums.
- `App\Models\SaleItem`, `App\Models\Customer` (`name`,`email`,`phone`,`address`,`notes`), `App\Models\Unit` (`name`,`symbol`), `App\Models\Product` (`unit_id`,`sku`).
- `App\Enums\PaymentMethod` = `CASH='cash'`, `TRANSFER='transfer'` + `label()`.
- `App\DTOs\SaleData` (readonly) con `fromArray`/`toArray`.
- `App\Services\SaleService::createSale` — en `DB::transaction`; calcula `$total = $totalSubtotal - $data->global_discount` y hace `$sale->update([... 'total' => $total ...])` (líneas ~150-170). **Ahí se agrega el desglose fiscal.** Al final postea contabilidad si `COMPLETED`.
- `App\Http\Requests\StoreSaleRequest` — reglas de la venta del POS.
- `resources/views/sales/create.blade.php` — POS Alpine: `selectedCustomer` (TomSelect vía `ajax.customers.search`), `payment.method`, submit fetch con `{customer_id, items, payment_method, ...}` (~línea 730), modal inline `customer-modal` que postea a `ajax.customers.store` (~líneas 778-827).
- `App\Livewire\Customers\CustomerForm`, `App\Livewire\Products\ProductForm`, `App\Livewire\Units\UnitForm` — formularios admin.
- Settings `tax_iva_rate`, `tax_it_rate` (via `App\Models\Setting::get`).

---

## File Structure

- Modify `app/Enums/PaymentMethod.php` — QR, CARD, `siatCode()`.
- Create migrations: identidad en `customers`; `sin_code` en `products` y `units`; columnas fiscales + `wants_invoice` en `sales`.
- Modify `app/Models/{Customer,Product,Unit,Sale}.php` — fillable/casts + métodos de identidad en Customer.
- Create `app/Fiscal/BillingIdentity.php` — objeto de valor.
- Create `app/Fiscal/SaleTaxCalculator.php` — cálculo del desglose.
- Modify `app/DTOs/SaleData.php` — `wants_invoice`.
- Modify `app/Services/SaleService.php` — persistir desglose + `wants_invoice`.
- Modify `app/Http/Requests/StoreSaleRequest.php` — `wants_invoice` + guard identidad.
- Modify `app/Livewire/Customers/CustomerForm.php` (+ su blade) — campos de identidad.
- Modify el endpoint `ajax.customers.store` (controlador) — validar/guardar identidad.
- Modify `app/Livewire/Products/ProductForm.php` + `app/Livewire/Units/UnitForm.php` (+ blades) — `sin_code`.
- Modify `resources/views/sales/create.blade.php` — toggle "¿Factura?", identidad en el modal inline, `wants_invoice` en el POST.
- Tests: `tests/Feature/Fiscal/PaymentMethodSiatTest.php`, `BillingIdentityTest.php`, `SaleTaxCalculatorTest.php`, `SaleFiscalPersistenceTest.php`, `CustomerBillingIdentityFormTest.php`, `ProductUnitSinCodeTest.php`, y feature del POS store.

---

## Task 1: `PaymentMethod` con QR/CARD y `siatCode()`

**Files:** Modify `app/Enums/PaymentMethod.php` · Test `tests/Feature/Fiscal/PaymentMethodSiatTest.php`

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Fiscal;

use App\Enums\PaymentMethod;
use Tests\TestCase;

class PaymentMethodSiatTest extends TestCase
{
    public function test_new_cases_exist(): void
    {
        $this->assertSame('qr', PaymentMethod::QR->value);
        $this->assertSame('card', PaymentMethod::CARD->value);
    }

    public function test_siat_code_maps_every_case(): void
    {
        $this->assertSame(1, PaymentMethod::CASH->siatCode());
        $this->assertSame(2, PaymentMethod::CARD->siatCode());
        $this->assertSame(7, PaymentMethod::QR->siatCode());
        $this->assertSame(1, PaymentMethod::TRANSFER->siatCode()); // transferencia → efectivo/otros según paramétrica

        // Ningún caso queda sin código (protege ante agregar uno nuevo sin mapearlo).
        foreach (PaymentMethod::cases() as $case) {
            $this->assertIsInt($case->siatCode());
        }
    }

    public function test_labels_exist_for_new_cases(): void
    {
        $this->assertNotSame('', PaymentMethod::QR->label());
        $this->assertNotSame('', PaymentMethod::CARD->label());
    }
}
```

- [ ] **Step 2: Correr — falla**

Run: `php artisan test --filter PaymentMethodSiatTest` → `QR` no existe.

- [ ] **Step 3: Implementar**

Reemplazar `app/Enums/PaymentMethod.php` por:
```php
<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case CASH = 'cash';
    case TRANSFER = 'transfer';
    case QR = 'qr';
    case CARD = 'card';

    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Efectivo',
            self::TRANSFER => 'Transferencia',
            self::QR => 'QR',
            self::CARD => 'Tarjeta',
        };
    }

    /**
     * Código de la paramétrica "método de pago" del SIN. Los valores exactos salen
     * del catálogo oficial (sincronizado en F1); acá van los de uso común. Es la
     * única fuente del mapeo — F1 lo lee para armar la factura.
     */
    public function siatCode(): int
    {
        return match ($this) {
            self::CASH => 1,      // Efectivo
            self::CARD => 2,      // Tarjeta
            self::QR => 7,        // Transferencia bancaria / pago QR
            self::TRANSFER => 1,  // Sin código propio distinto en el uso común
        };
    }
}
```
NOTA: el `label()` cambió de inglés a español (antes 'Cash'/'Bank Transfer'). Verificar que ninguna vista dependa del texto literal en inglés; si alguna lo hace, ajustarla. Es mejora consistente con el resto de la UI en español.

- [ ] **Step 4: Correr — pasa**

Run: `php artisan test --filter PaymentMethodSiatTest`

- [ ] **Step 5: Commit**

```bash
git add app/Enums/PaymentMethod.php tests/Feature/Fiscal/PaymentMethodSiatTest.php
git commit -m "feat(fiscal): PaymentMethod suma QR y CARD con siatCode()"
```

---

## Task 2: Migraciones + modelos + `BillingIdentity` + métodos de Customer

**Files:** 3 migraciones nuevas · Modify `app/Models/{Customer,Product,Unit,Sale}.php` · Create `app/Fiscal/BillingIdentity.php` · Test `tests/Feature/Fiscal/BillingIdentityTest.php`

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Fiscal;

use App\Fiscal\BillingIdentity;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_holds_identity_columns(): void
    {
        $c = Customer::create([
            'name' => 'Juan', 'doc_type' => '1', 'doc_number' => '5115889',
            'doc_complement' => '1A', 'business_name' => 'Juan SRL',
        ]);

        $this->assertSame('5115889', $c->fresh()->doc_number);
    }

    public function test_billing_identity_object(): void
    {
        $id = new BillingIdentity('1', '5115889', '1A', 'Juan SRL');
        $this->assertTrue($id->isComplete());

        $incomplete = new BillingIdentity('1', '', null, null);
        $this->assertFalse($incomplete->isComplete());
    }

    public function test_customer_billing_identity_helpers(): void
    {
        $with = Customer::create(['name' => 'A', 'doc_type' => '1', 'doc_number' => '123']);
        $without = Customer::create(['name' => 'B']);

        $this->assertTrue($with->hasBillingIdentity());
        $this->assertInstanceOf(BillingIdentity::class, $with->billingIdentity());
        $this->assertFalse($without->hasBillingIdentity());
        $this->assertNull($without->billingIdentity());
    }

    public function test_product_and_unit_hold_sin_code(): void
    {
        $unit = Unit::create(['name' => 'Pieza', 'symbol' => 'pza', 'sin_code' => '1']);
        $product = Product::factory()->create(['sin_code' => '49111']);

        $this->assertSame('1', $unit->fresh()->sin_code);
        $this->assertSame('49111', $product->fresh()->sin_code);
    }

    public function test_sale_holds_fiscal_columns(): void
    {
        $sale = Sale::factory()->create([
            'taxable_base' => 10000, 'iva_amount' => 1300, 'it_amount' => 300, 'wants_invoice' => true,
        ]);

        $fresh = $sale->fresh();
        $this->assertSame(10000, $fresh->taxable_base);
        $this->assertTrue($fresh->wants_invoice);
    }
}
```
NOTA: usar `Product::factory()`/`Sale::factory()` como el repo (ver `database/factories/`). Si `Sale::factory()` requiere campos, seguir la convención de `tests/Feature/Shop/ShareMetaBuilderTest.php` o `SaleService` tests existentes.

- [ ] **Step 2: Correr — falla**

Run: `php artisan test --filter BillingIdentityTest`

- [ ] **Step 3: Migración de identidad en `customers`**

`database/migrations/2026_07_20_170000_add_billing_identity_to_customers.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('doc_type')->nullable()->after('name');       // código tipo doc SIN (1-5)
            $table->string('doc_number')->nullable()->after('doc_type'); // NIT o CI
            $table->string('doc_complement')->nullable()->after('doc_number');
            $table->string('business_name')->nullable()->after('doc_complement'); // razón social
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['doc_type', 'doc_number', 'doc_complement', 'business_name']);
        });
    }
};
```

- [ ] **Step 4: Migración `sin_code` en `products` y `units`**

`database/migrations/2026_07_20_170100_add_sin_code_to_products_and_units.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('sin_code')->nullable()->after('sku'); // homologación catálogo SIN
        });
        Schema::table('units', function (Blueprint $table) {
            $table->string('sin_code')->nullable()->after('symbol'); // código unidad de medida SIN
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('sin_code'));
        Schema::table('units', fn (Blueprint $t) => $t->dropColumn('sin_code'));
    }
};
```

- [ ] **Step 5: Migración columnas fiscales en `sales`**

`database/migrations/2026_07_20_170200_add_fiscal_columns_to_sales.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->bigInteger('taxable_base')->default(0)->after('total'); // base para débito fiscal (centavos)
            $table->bigInteger('iva_amount')->default(0)->after('taxable_base'); // débito fiscal 13%
            $table->bigInteger('it_amount')->default(0)->after('iva_amount');    // IT 3%
            $table->boolean('wants_invoice')->default(false)->after('it_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['taxable_base', 'iva_amount', 'it_amount', 'wants_invoice']);
        });
    }
};
```

- [ ] **Step 6: Actualizar modelos**

`app/Models/Customer.php` — agregar a `$fillable` `'doc_type','doc_number','doc_complement','business_name'` y estos métodos:
```php
    public function billingIdentity(): ?\App\Fiscal\BillingIdentity
    {
        if (! $this->hasBillingIdentity()) {
            return null;
        }

        return new \App\Fiscal\BillingIdentity(
            (string) $this->doc_type,
            (string) $this->doc_number,
            $this->doc_complement,
            $this->business_name ?: $this->name,
        );
    }

    public function hasBillingIdentity(): bool
    {
        return filled($this->doc_type) && filled($this->doc_number);
    }
```
`app/Models/Product.php` — agregar `'sin_code'` a `$fillable`.
`app/Models/Unit.php` — agregar `'sin_code'` a `$fillable`.
`app/Models/Sale.php` — agregar `'taxable_base','iva_amount','it_amount','wants_invoice'` a `$fillable`, y a `$casts`: `'taxable_base'=>'integer','iva_amount'=>'integer','it_amount'=>'integer','wants_invoice'=>'boolean'`.

- [ ] **Step 7: `BillingIdentity`**

`app/Fiscal/BillingIdentity.php`:
```php
<?php

namespace App\Fiscal;

/**
 * Identidad tributaria del comprador para una factura. Objeto de valor inmutable,
 * framework-agnóstico: lo llena el POS hoy y la tienda/bot después, sin refactor.
 * NO verifica el NIT contra el SIN (eso es F1); solo valida forma.
 */
readonly class BillingIdentity
{
    public function __construct(
        public string $docType,        // código tipo doc SIN (1-5)
        public string $docNumber,      // NIT o CI
        public ?string $complement = null,
        public ?string $businessName = null,
    ) {}

    public function isComplete(): bool
    {
        return trim($this->docType) !== '' && trim($this->docNumber) !== '';
    }

    /** @return array<string,?string> */
    public function toArray(): array
    {
        return [
            'doc_type' => $this->docType,
            'doc_number' => $this->docNumber,
            'doc_complement' => $this->complement,
            'business_name' => $this->businessName,
        ];
    }
}
```

- [ ] **Step 8: Correr — pasa**

Run: `php artisan test --filter BillingIdentityTest`

- [ ] **Step 9: Commit**

```bash
git add database/migrations app/Models app/Fiscal/BillingIdentity.php tests/Feature/Fiscal/BillingIdentityTest.php
git commit -m "feat(fiscal): campos de identidad, sin_code y desglose fiscal + BillingIdentity"
```

---

## Task 3: `SaleTaxCalculator` + integración en la venta

**Files:** Create `app/Fiscal/SaleTaxCalculator.php` · Modify `app/DTOs/SaleData.php`, `app/Services/SaleService.php` · Test `tests/Feature/Fiscal/SaleTaxCalculatorTest.php`, `SaleFiscalPersistenceTest.php`

- [ ] **Step 1: Test del calculador (falla)**

```php
<?php

namespace Tests\Feature\Fiscal;

use App\Fiscal\SaleTaxCalculator;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleTaxCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_computes_iva_and_it_from_settings(): void
    {
        Setting::set('tax_iva_rate', '13');
        Setting::set('tax_it_rate', '3');

        // total 100.00 = 10000 centavos
        $r = (new SaleTaxCalculator())->forTotal(10000);

        $this->assertSame(10000, $r['taxable_base']);
        $this->assertSame(1300, $r['iva_amount']); // 13% de 10000
        $this->assertSame(300, $r['it_amount']);   // 3% de 10000
    }

    public function test_zero_rates_yield_zero(): void
    {
        Setting::set('tax_iva_rate', '0');
        Setting::set('tax_it_rate', '0');

        $r = (new SaleTaxCalculator())->forTotal(10000);

        $this->assertSame(0, $r['iva_amount']);
        $this->assertSame(0, $r['it_amount']);
    }

    public function test_unset_rates_do_not_crash(): void
    {
        $r = (new SaleTaxCalculator())->forTotal(10000);

        $this->assertSame(0, $r['iva_amount']);
        $this->assertSame(0, $r['it_amount']);
    }

    public function test_total_zero(): void
    {
        Setting::set('tax_iva_rate', '13');
        $r = (new SaleTaxCalculator())->forTotal(0);
        $this->assertSame(0, $r['taxable_base']);
        $this->assertSame(0, $r['iva_amount']);
    }
}
```

- [ ] **Step 2: Correr — falla**

Run: `php artisan test --filter SaleTaxCalculatorTest`

- [ ] **Step 3: Implementar el calculador**

`app/Fiscal/SaleTaxCalculator.php`:
```php
<?php

namespace App\Fiscal;

use App\Models\Setting;

/**
 * Desglose fiscal de una venta (caso general, sin ICE/IEHD/exentos). En Bolivia el
 * IVA va incluido en el precio: la base para débito fiscal es el total (menos
 * exentos/giftcard, hoy 0), el débito fiscal es 13% de esa base, y el IT 3% del total.
 *
 * OJO tributario: la fórmula exacta (exentos, IVA por dentro/fuera, redondeos) la
 * confirma el contador del contribuyente. Este cálculo es el caso general y es
 * sobrescribible; la validez fiscal no es responsabilidad del código.
 *
 * Todos los montos en centavos (enteros), como el resto de la capa de ventas.
 *
 * @return array{taxable_base:int, iva_amount:int, it_amount:int}
 */
class SaleTaxCalculator
{
    public function forTotal(int $totalCents, int $exemptCents = 0, int $giftCardCents = 0): array
    {
        $base = max(0, $totalCents - $exemptCents - $giftCardCents);

        $ivaRate = (float) Setting::get('tax_iva_rate', '0'); // %
        $itRate  = (float) Setting::get('tax_it_rate', '0');  // %

        return [
            'taxable_base' => $base,
            'iva_amount'   => (int) round($base * $ivaRate / 100),
            'it_amount'    => (int) round($totalCents * $itRate / 100),
        ];
    }
}
```

- [ ] **Step 4: Correr — pasa**

Run: `php artisan test --filter SaleTaxCalculatorTest`

- [ ] **Step 5: `SaleData` suma `wants_invoice`**

En `app/DTOs/SaleData.php`: agregar el parámetro `public bool $wants_invoice = false,` al constructor (al final, antes o después de `$source` — mantené el orden y actualizá llamadores por nombre si hiciera falta; todos los existentes lo omiten y toman el default). En `fromArray` agregar `wants_invoice: (bool) ($data['wants_invoice'] ?? false),` y en `toArray` `'wants_invoice' => $this->wants_invoice,`.

- [ ] **Step 6: Persistir el desglose en `createSale`**

En `app/Services/SaleService.php`, inyectar el calculador en el constructor:
```php
        protected \App\Fiscal\SaleTaxCalculator $taxCalculator,
```
Y en el `$sale->update([...])` que setea `'total' => $total` (líneas ~164-170), agregar el desglose y el flag. Reemplazar ese `update` por:
```php
                $tax = $this->taxCalculator->forTotal($total);

                $sale->update([
                    'subtotal' => $totalSubtotal + $totalDiscount,
                    'total_discount' => $totalDiscount + $data->global_discount,
                    'global_discount' => $data->global_discount,
                    'total' => $total,
                    'change' => $change,
                    'taxable_base' => $tax['taxable_base'],
                    'iva_amount' => $tax['iva_amount'],
                    'it_amount' => $tax['it_amount'],
                    'wants_invoice' => $data->wants_invoice,
                ]);
```

- [ ] **Step 7: Test de persistencia + no-regresión (falla, luego pasa)**

`tests/Feature/Fiscal/SaleFiscalPersistenceTest.php`:
```php
<?php

namespace Tests\Feature\Fiscal;

use App\DTOs\SaleData;
use App\Enums\PaymentMethod;
use App\Models\Setting;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleFiscalPersistenceTest extends TestCase
{
    use RefreshDatabase;

    // Reproducir el setup mínimo de venta del repo (producto con stock, ubicación, etc.).
    // Seguir el patrón de los tests existentes de SaleService / ReservationControllerTest.

    public function test_sale_persists_fiscal_breakdown(): void
    {
        Setting::set('tax_iva_rate', '13');
        Setting::set('tax_it_rate', '3');

        // … armar un SaleData con un item cuyo total conocido sea, p.ej., 10000 …
        // $sale = app(SaleService::class)->createSale($data);
        // $this->assertSame(1300, $sale->fresh()->iva_amount);
        // $this->assertSame(300, $sale->fresh()->it_amount);
        $this->markTestIncomplete('Completar con el setup de venta del repo');
    }

    public function test_quick_sale_without_invoice_still_works(): void
    {
        // Venta rápida: wants_invoice omitido → false, sin cliente. Debe crearse igual que hoy.
        // $sale = app(SaleService::class)->createSale($quickData);
        // $this->assertFalse($sale->fresh()->wants_invoice);
        $this->markTestIncomplete('Completar con el setup de venta del repo');
    }
}
```
IMPORTANTE para el implementador: **reemplazar los `markTestIncomplete` por tests reales.** Leer un test existente que ejerza `SaleService::createSale` (p.ej. buscar en `tests/` quién arma `SaleData` con stock) y copiar ese setup (producto, `ProductStock`, `Location`, `Warehouse`). El objetivo: (a) el desglose se persiste, (b) la venta rápida sin `wants_invoice`/sin cliente se crea sin romperse. No dejar tests incompletos en el commit.

- [ ] **Step 8: Correr — pasa** (`php artisan test --filter "SaleTaxCalculatorTest|SaleFiscalPersistenceTest"`)

- [ ] **Step 9: Regresión de ventas** — `php artisan test --filter "SaleService|Sale"` (que ningún test de venta existente se rompa por la firma nueva del constructor / DTO).

- [ ] **Step 10: Commit**

```bash
git add app/Fiscal/SaleTaxCalculator.php app/DTOs/SaleData.php app/Services/SaleService.php tests/Feature/Fiscal/SaleTaxCalculatorTest.php tests/Feature/Fiscal/SaleFiscalPersistenceTest.php
git commit -m "feat(fiscal): calcula y persiste desglose IVA/IT y wants_invoice por venta"
```

---

## Task 4: Identidad en el formulario de cliente (admin + POS inline)

**Files:** Modify `app/Livewire/Customers/CustomerForm.php` (+ su blade) · el controlador de `ajax.customers.store` · Test `tests/Feature/Fiscal/CustomerBillingIdentityFormTest.php`

READ FIRST: `app/Livewire/Customers/CustomerForm.php` + su vista, y el controlador detrás de `route('ajax.customers.store')` (buscar en `routes/web.php` el nombre `ajax.customers.store`). Seguir su patrón de propiedades/validación.

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Fiscal;

use App\Livewire\Customers\CustomerForm;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerBillingIdentityFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_form_saves_identity(): void
    {
        // Autenticar como corresponda (ver otros tests de Livewire admin del repo).
        Livewire::test(CustomerForm::class)
            ->set('name', 'Juan')
            ->set('doc_type', '1')
            ->set('doc_number', '5115889')
            ->set('business_name', 'Juan SRL')
            ->call('save');

        $c = Customer::where('doc_number', '5115889')->firstOrFail();
        $this->assertTrue($c->hasBillingIdentity());
    }
}
```
Ajustar los nombres de propiedad/método (`save`/`store`/etc.) a los reales de `CustomerForm`.

- [ ] **Step 2: Correr — falla** (`php artisan test --filter CustomerBillingIdentityFormTest`)

- [ ] **Step 3: Agregar los campos a `CustomerForm`**

- Agregar propiedades públicas `doc_type`, `doc_number`, `doc_complement`, `business_name` (string, default '').
- Cargar/guardar esos campos junto a los existentes (en `mount`/`save` o como use el componente).
- Reglas de validación (forma, no verificación SIN): `doc_type` nullable string, `doc_number` nullable string max 20, `doc_complement` nullable string max 5, `business_name` nullable string max 240.
- En el blade del `CustomerForm`, agregar los 4 inputs (con un `<select>` para `doc_type`: opciones CI/NIT/CEX/Pasaporte/Otro → valores 1..5) siguiendo el estilo del form.

- [ ] **Step 4: Agregar los campos al endpoint `ajax.customers.store`**

En el controlador detrás de `ajax.customers.store`: sumar `doc_type`, `doc_number`, `doc_complement`, `business_name` a la validación (mismas reglas de forma) y al `Customer::create(...)`. Así el modal inline del POS puede capturar identidad al crear un cliente nuevo.

- [ ] **Step 5: Correr — pasa** (`php artisan test --filter CustomerBillingIdentityFormTest`)

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Customers/CustomerForm.php resources/views/livewire/customers app/Http/Controllers tests/Feature/Fiscal/CustomerBillingIdentityFormTest.php
git commit -m "feat(fiscal): captura identidad tributaria del cliente (form admin + POS)"
```

---

## Task 5: `sin_code` en formularios de producto y unidad

**Files:** Modify `app/Livewire/Products/ProductForm.php` (+ blade), `app/Livewire/Units/UnitForm.php` (+ blade) · Test `tests/Feature/Fiscal/ProductUnitSinCodeTest.php`

READ FIRST: ambos componentes y sus vistas; seguir su patrón exacto.

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Fiscal;

use App\Livewire\Units\UnitForm;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductUnitSinCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_unit_form_saves_sin_code(): void
    {
        Livewire::test(UnitForm::class)
            ->set('name', 'Pieza')
            ->set('symbol', 'pza')
            ->set('sin_code', '1')
            ->call('save');

        $this->assertSame('1', Unit::where('name', 'Pieza')->firstOrFail()->sin_code);
    }
}
```
Ajustar nombres de propiedad/método a los reales. Agregar el equivalente para `ProductForm` si su setup es abordable; si el `ProductForm` es muy pesado de montar en test, al menos cubrir `UnitForm` y verificar `ProductForm` a mano, dejándolo anotado.

- [ ] **Step 2: Correr — falla**

- [ ] **Step 3: Agregar `sin_code`** a `UnitForm` (propiedad + regla `nullable|string|max:20` + persistencia + input en el blade) y a `ProductForm` (ídem, en la sección de datos del producto).

- [ ] **Step 4: Correr — pasa**

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Products/ProductForm.php app/Livewire/Units/UnitForm.php resources/views/livewire/products resources/views/livewire/units tests/Feature/Fiscal/ProductUnitSinCodeTest.php
git commit -m "feat(fiscal): campo codigo SIN en productos y unidades"
```

---

## Task 6: Cableado del POS (toggle factura + identidad + wants_invoice)

**Files:** Modify `app/Http/Requests/StoreSaleRequest.php`, `resources/views/sales/create.blade.php` · Test `tests/Feature/Fiscal/PosStoreWantsInvoiceTest.php`

READ FIRST: `resources/views/sales/create.blade.php` completo (el `pos()` de Alpine, el submit ~línea 730, el modal `customer-modal` ~778) y `StoreSaleRequest`.

- [ ] **Step 1: Test que falla (a nivel request/controlador)**

`tests/Feature/Fiscal/PosStoreWantsInvoiceTest.php`:
```php
<?php

namespace Tests\Feature\Fiscal;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosStoreWantsInvoiceTest extends TestCase
{
    use RefreshDatabase;

    // Setup mínimo de venta (producto con stock, usuario autenticado) siguiendo los
    // tests de SalesController existentes.

    public function test_store_persists_wants_invoice_with_identified_customer(): void
    {
        // $customer = Customer con identidad completa; POST a sales.store con wants_invoice=1 y customer_id
        // $this->assertDatabaseHas('sales', ['wants_invoice' => true]);
        $this->markTestIncomplete('Completar con el setup de SalesController del repo');
    }

    public function test_wants_invoice_requires_customer_with_identity(): void
    {
        // POST con wants_invoice=1 SIN customer_id (o cliente sin identidad) → error de validación 422.
        $this->markTestIncomplete('Completar con el setup de SalesController del repo');
    }
}
```
Reemplazar los `markTestIncomplete` por tests reales usando el patrón de los tests de `SalesController@store` del repo.

- [ ] **Step 2: Correr — falla**

- [ ] **Step 3: `StoreSaleRequest` — regla + guard**

Agregar a `rules()`:
```php
            'wants_invoice' => ['nullable', 'boolean'],
```
Y un guard: si `wants_invoice` es verdadero, exigir `customer_id` presente y que ese cliente tenga identidad completa. Con `withValidator`:
```php
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {
            if (! $this->boolean('wants_invoice')) {
                return;
            }
            $customer = $this->input('customer_id')
                ? \App\Models\Customer::find($this->input('customer_id'))
                : null;

            if (! $customer || ! $customer->hasBillingIdentity()) {
                $v->errors()->add('wants_invoice', 'Para factura, elegí un cliente con NIT/CI cargado.');
            }
        });
    }
```

- [ ] **Step 4: POS Alpine — toggle, identidad en el modal, `wants_invoice` en el POST**

En `resources/views/sales/create.blade.php`:
- En el estado `pos()` agregar `wantsInvoice: false,` (persistir en localStorage junto a los otros si querés).
- Un toggle "¿Factura? (NIT/CI)" en el panel de pago/cliente, `x-model="wantsInvoice"`. Mostrar aviso si `wantsInvoice && !selectedCustomer` ("elegí un cliente con NIT para facturar").
- En el objeto del submit (~línea 730), agregar `wants_invoice: this.wantsInvoice,`.
- En el modal inline `customer-modal` (~778-827), agregar los 4 campos de identidad (select tipo doc + número + complemento + razón social) al form y al body del POST a `ajax.customers.store` (Task 4 ya acepta esos campos server-side).
- Extender el selector de método de pago para incluir **QR** y **Tarjeta** (Task 1 ya los tiene en el enum).

Nota: dejar la venta rápida intacta — con `wantsInvoice=false` (default) el POST es idéntico al de hoy más un `wants_invoice:false` inocuo.

- [ ] **Step 5: Correr — pasa** (`php artisan test --filter PosStoreWantsInvoiceTest`)

- [ ] **Step 6: Verificación manual del POS** (no automatizable del todo): abrir `/sales/create`, hacer una venta rápida (sin tocar nada → funciona), y una con "¿Factura?" + cliente con NIT (persiste `wants_invoice`). Registrar resultado.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/StoreSaleRequest.php resources/views/sales/create.blade.php tests/Feature/Fiscal/PosStoreWantsInvoiceTest.php
git commit -m "feat(fiscal): POS marca venta para factura con cliente identificado"
```

---

## Cierre

- [ ] **Suite completa** — `php artisan test`. Sin regresiones; atención a los tests de ventas, POS y Telegram (todos llaman `createSale`).
- [ ] **Build** — `npm run build` (por los cambios en el POS blade, aunque son Alpine inline; confirmar que compila).
- [ ] **Nota de deploy** — `php artisan migrate` (aditiva, NUNCA `:fresh`) + `php artisan cache:clear`. Sin datos que sembrar.

Al terminar → **superpowers:finishing-a-development-branch**.

---

## Self-review (checklist del autor)

- **Cobertura de spec:** R1 (T2), R2 (T2), R3 (T2+T3), R4 (T1), R5 (T2), R6 (T2), R7 (T4, núcleo VO + captura POS/admin), R8 (T3), R9 (T3), R10 (T3 test de no-regresión), R11 (T5), R12 (T3). ✔
- **Sin placeholders de código:** el código está completo salvo dos tests de integración de venta (T3 Step 7, T6 Step 1) marcados con `markTestIncomplete` **con instrucción explícita de completarlos con el setup real del repo** — porque el armado de una venta (producto+stock+ubicación) depende de fixtures que el implementador debe copiar del test existente, no inventar. Están señalados como obligatorios de completar antes del commit. ✔
- **Consistencia:** `BillingIdentity` (docType/docNumber/complement/businessName) igual en VO, Customer, forms. `SaleTaxCalculator::forTotal` → array {taxable_base,iva_amount,it_amount} usado igual en el test y en `createSale`. `wants_invoice` fluye DTO→Service→columna, default false en todos los llamadores existentes. `PaymentMethod::siatCode()` cubre los 4 casos. ✔
- **Riesgo anotado:** cambio de firma del constructor de `SaleService` y del `SaleData` → todos los llamadores existentes toman defaults (DTO) o inyección (constructor); T3 Step 9 corre la regresión de ventas para confirmarlo. El `label()` del enum cambió a español → T1 Step 3 avisa de revisar dependencias del texto. ✔
