# IVA/IT en Asientos de Venta y Compra — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Postear IVA/IT en los asientos de venta/compra con factura (leyendo lo que F0 calcula), sin tocar la venta rápida, y hacer que el reporte lea los saldos fiscales reales.

**Architecture:** Reusa `JournalEntryService`. Un colaborador nuevo `TaxLinesBuilder` arma las líneas fiscales (mapeo por Setting). Los Services de venta/compra reducen la línea base al neto y agregan las líneas fiscales solo si `wants_invoice`. El reporte lee saldos reales en vez de estimar.

**Tech Stack:** Laravel 11, PHPUnit class-style + RefreshDatabase, MySQL. Sin dependencias nuevas.

**Spec:** `docs/superpowers/specs/2026-08-27-contabilidad-impuestos-asientos-design.md`

---

## Convenciones
- **NUNCA `migrate:fresh`** (MySQL dev compartido). Migraciones aditivas. Seeders idempotentes (`updateOrCreate`).
- Dinero en **centavos-int**. Cuentas por `Setting::get('accounting_*_code', default)`.
- Tests: `extends Tests\TestCase`, `use RefreshDatabase`. Correr `php artisan test --filter <Clase>`.

## Anclajes verificados
- Venta: `SaleService.php:165-177` calcula `taxCalculator->forTotal($total)` y persiste `taxable_base/iva_amount/it_amount/wants_invoice`. `SaleAccountingService::postCompletedSale` (`app/Services/Accounting/SaleAccountingService.php:40-96`) postea 4 líneas con `$sale->total`, sin impuestos. `findPostingAccount($code)` en `:120-133`.
- Compra: `PurchaseAccountingService::postPurchase` (`app/Services/Accounting/PurchaseAccountingService.php:40-83`) postea Inventario=total / Caja|CxP=total. `purchases` NO tiene columnas fiscales.
- F0: `App\Fiscal\SaleTaxCalculator::forTotal(int $total): array{taxable_base,iva_amount,it_amount}` (`app/Fiscal/SaleTaxCalculator.php:22-34`).
- Reporte: `FinancialStatementService::buildIncomeStatement` (`app/Services/FinancialStatementService.php:105-129`) pasa `$periodBalances` (colección con TODOS los saldos del período, incl. cuentas fiscales) y llama `buildTaxBreakdown(incomeTotal,costTotal,expenseTotal,withTaxes)` (`:134-188`) que hoy ESTIMA por %.
- Cuentas fiscales sembradas: DF-IVA `2.1.11`, CF-IVA `1.1.05`, IT x pagar `2.1.12` (`database/seeders/ChartOfAccountSeeder.php`). Falta gasto IT.

## File Structure
- Create `database/migrations/2026_08_27_000002_seed_tax_account_codes_and_rates.php`.
- Modify `database/seeders/ChartOfAccountSeeder.php` — cuenta gasto IT 5.2.01.
- Create `app/Services/Accounting/TaxLinesBuilder.php`.
- Modify `app/Services/Accounting/SaleAccountingService.php`.
- Create `database/migrations/2026_08_27_000003_add_fiscal_columns_to_purchases.php`.
- Create `app/Fiscal/PurchaseTaxCalculator.php`.
- Modify `app/Services/PurchaseService.php` (calcular+persistir fiscal) y `app/Models/Purchase.php` (fillable/casts).
- Modify `app/Services/Accounting/PurchaseAccountingService.php`.
- Modify `app/Services/FinancialStatementService.php` — buildTaxBreakdown lee saldos reales.
- Tests en `tests/Feature/Accounting/` y `tests/Feature/Finance/`.

---

## Task 1: Settings de cuentas fiscales + tasas + cuenta gasto IT

**Files:** Create `database/migrations/2026_08_27_000002_seed_tax_account_codes_and_rates.php` · Modify `database/seeders/ChartOfAccountSeeder.php` · Test `tests/Feature/Accounting/TaxAccountSettingsTest.php`

- [ ] **Step 1: Test que falla**
```php
<?php

namespace Tests\Feature\Accounting;

use App\Models\ChartOfAccount;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxAccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tax_settings_and_it_expense_account_seeded(): void
    {
        $this->assertSame('2.1.11', Setting::get('accounting_df_iva_code'));
        $this->assertSame('1.1.05', Setting::get('accounting_cf_iva_code'));
        $this->assertSame('2.1.12', Setting::get('accounting_it_payable_code'));
        $this->assertSame('5.2.01', Setting::get('accounting_it_expense_code'));
        $this->assertSame('13', Setting::get('tax_iva_rate'));
        $this->assertSame('3', Setting::get('tax_it_rate'));

        $itExpense = ChartOfAccount::where('code', '5.2.01')->first();
        $this->assertNotNull($itExpense);
        $this->assertSame('expense', $itExpense->account_type);
        $this->assertTrue((bool) $itExpense->allows_posting);
    }
}
```
NOTE: RefreshDatabase runs migrations (settings) but seeders run only if invoked. If the ChartOfAccount seed for 5.2.01 lives in `ChartOfAccountSeeder`, this test must seed it — call `$this->seed(\Database\Seeders\ChartOfAccountSeeder::class);` in the test before asserting the account, OR put the 5.2.01 account creation in the migration itself (preferred for testability — see Step 3). Decide and keep the test green.

- [ ] **Step 2: Correr — falla**

- [ ] **Step 3: Migración** — settings + (para testabilidad) crear la cuenta gasto IT idempotente aquí mismo, además de en el seeder. READ `database/seeders/ChartOfAccountSeeder.php` para copiar las columnas exactas de una cuenta (code, name, level, parent_id, account_type, normal_balance, allows_posting, is_active) y su convención de parent.
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            'accounting_df_iva_code'     => '2.1.11',
            'accounting_cf_iva_code'     => '1.1.05',
            'accounting_it_payable_code' => '2.1.12',
            'accounting_it_expense_code' => '5.2.01',
            'tax_iva_rate'               => '13',
            'tax_it_rate'                => '3',
        ];
        foreach ($settings as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        // Cuenta de gasto IT (idempotente). Ajustar columnas/parent al esquema real de chart_of_accounts.
        if (! DB::table('chart_of_accounts')->where('code', '5.2.01')->exists()) {
            $parentId = DB::table('chart_of_accounts')->where('code', '5.2')->value('id'); // grupo gastos (si existe)
            DB::table('chart_of_accounts')->insert([
                'code' => '5.2.01', 'name' => 'Impuesto a las Transacciones', 'parent_id' => $parentId,
                'level' => 3, 'account_type' => 'expense', 'normal_balance' => 'debit',
                'allows_posting' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'accounting_df_iva_code', 'accounting_cf_iva_code', 'accounting_it_payable_code',
            'accounting_it_expense_code', 'tax_iva_rate', 'tax_it_rate',
        ])->delete();
        DB::table('chart_of_accounts')->where('code', '5.2.01')->delete();
    }
};
```
IMPORTANT: si `chart_of_accounts` tiene columnas NOT NULL adicionales (revisá la migración `2026_04_19_000003_create_chart_of_accounts_table.php`), agregalas al insert con valores válidos. Si `5.2` no existe como grupo, o `level`/`parent_id` deben ser coherentes, ajustá (mirá cómo el seeder crea las cuentas de gasto).

- [ ] **Step 4: Añadir la misma cuenta al `ChartOfAccountSeeder`** (idempotente `updateOrCreate` por `code`) para entornos que corren seeders, con los mismos valores. No dupliques (la migración ya la crea; el seeder la mantiene si alguien re-siembra).

- [ ] **Step 5: Correr — pasa**

- [ ] **Step 6: Commit** — `git commit -am "feat(contab): settings fiscales + cuenta gasto IT + homologacion de tasas"`

---

## Task 2: `TaxLinesBuilder`

**Files:** Create `app/Services/Accounting/TaxLinesBuilder.php` · Test `tests/Feature/Accounting/TaxLinesBuilderTest.php`

- [ ] **Step 1: Test que falla**
```php
<?php

namespace Tests\Feature\Accounting;

use App\Models\ChartOfAccount;
use App\Services\Accounting\TaxLinesBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxLinesBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function seedTaxAccounts(): void
    {
        foreach ([
            ['2.1.11', 'DF-IVA', 'liability', 'credit'], ['1.1.05', 'CF-IVA', 'asset', 'debit'],
            ['2.1.12', 'IT x pagar', 'liability', 'credit'], ['5.2.01', 'Gasto IT', 'expense', 'debit'],
        ] as [$code, $name, $type, $nb]) {
            ChartOfAccount::create(['code' => $code, 'name' => $name, 'level' => 3, 'account_type' => $type, 'normal_balance' => $nb, 'allows_posting' => true, 'is_active' => true]);
        }
    }

    public function test_sale_tax_lines(): void
    {
        $this->seedTaxAccounts();
        $lines = app(TaxLinesBuilder::class)->saleTaxLines(1300, 300); // iva 13.00, it 3.00

        // DF-IVA credito 1300, IT gasto debito 300, IT x pagar credito 300
        $df = collect($lines)->firstWhere('credit_amount', 1300);
        $this->assertNotNull($df);
        $itDebit = collect($lines)->firstWhere('debit_amount', 300);
        $itCredit = collect($lines)->firstWhere('credit_amount', 300);
        $this->assertNotNull($itDebit);
        $this->assertNotNull($itCredit);
        $this->assertSame(3, count($lines));
    }

    public function test_purchase_tax_lines(): void
    {
        $this->seedTaxAccounts();
        $lines = app(TaxLinesBuilder::class)->purchaseTaxLines(1300); // cf-iva 13.00 debito

        $this->assertSame(1, count($lines));
        $this->assertSame(1300, $lines[0]['debit_amount']);
        $this->assertSame(0, $lines[0]['credit_amount']);
    }
}
```
- [ ] **Step 2: Correr — falla**
- [ ] **Step 3: Implementar**
```php
<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\Setting;
use RuntimeException;

/**
 * Arma las líneas fiscales de un asiento a partir de montos ya calculados (F0).
 * NO ajusta las líneas base (Ventas/Inventario): eso lo hace el Service reduciéndolas
 * al neto. El builder solo emite las líneas de impuesto con los montos exactos.
 */
class TaxLinesBuilder
{
    /**
     * Venta con factura: DF-IVA (crédito), IT gasto (débito), IT por pagar (crédito).
     * @return array<int, array{chart_of_account_id:int,debit_amount:int,credit_amount:int,description:string}>
     */
    public function saleTaxLines(int $ivaCents, int $itCents): array
    {
        $lines = [];
        if ($ivaCents > 0) {
            $lines[] = $this->line(Setting::get('accounting_df_iva_code', '2.1.11'), 0, $ivaCents, 'Débito Fiscal IVA');
        }
        if ($itCents > 0) {
            $lines[] = $this->line(Setting::get('accounting_it_expense_code', '5.2.01'), $itCents, 0, 'Impuesto a las Transacciones');
            $lines[] = $this->line(Setting::get('accounting_it_payable_code', '2.1.12'), 0, $itCents, 'IT por Pagar');
        }
        return $lines;
    }

    /** Compra con factura: CF-IVA (débito). Sin IT (IT es solo ventas). */
    public function purchaseTaxLines(int $ivaCents): array
    {
        if ($ivaCents <= 0) {
            return [];
        }
        return [$this->line(Setting::get('accounting_cf_iva_code', '1.1.05'), $ivaCents, 0, 'Crédito Fiscal IVA')];
    }

    private function line(string $code, int $debit, int $credit, string $desc): array
    {
        return [
            'chart_of_account_id' => $this->accountId($code),
            'debit_amount' => $debit,
            'credit_amount' => $credit,
            'description' => $desc,
        ];
    }

    private function accountId(string $code): int
    {
        $account = ChartOfAccount::query()
            ->where('code', $code)->where('is_active', true)->where('allows_posting', true)->first();
        if (! $account) {
            throw new RuntimeException("No existe cuenta contable activa/imputable con código {$code}.");
        }
        return $account->id;
    }
}
```
- [ ] **Step 4: Correr — pasa**
- [ ] **Step 5: Commit** — `git commit -am "feat(contab): TaxLinesBuilder para lineas fiscales de venta/compra"`

---

## Task 3: IVA/IT en el asiento de venta

**Files:** Modify `app/Services/Accounting/SaleAccountingService.php` · Test `tests/Feature/Accounting/SaleTaxPostingTest.php`

- [ ] **Step 1: Test que falla** — crea `tests/Feature/Accounting/SaleTaxPostingTest.php` con dos casos: venta CON factura (7 líneas, Ventas=neto, DF-IVA/IT correctos, cuadra) y venta rápida SIN factura (4 líneas, sin cuentas fiscales). Sembrar cuentas 1.1.01, 4.1, 5.1, 1.1.04, 2.1.11, 2.1.12, 5.2.01 + settings + un `AccountingPeriod` Open + un `Sale` con items. READ `tests/Feature/Finance/SaleAccountingTest.php` para copiar el armado de Sale/items/period. Asertar `entry->lines->sum('debit_amount') === entry->lines->sum('credit_amount')`, que la línea de Ventas (cuenta 4.1) sea `total - iva`, y que existan líneas DF-IVA=`iva`, IT gasto=`it`, IT x pagar=`it`. Para la venta rápida: `wants_invoice=false` → 4 líneas, ninguna cuenta fiscal.

- [ ] **Step 2: Correr — falla**

- [ ] **Step 3: Implementar** — en `SaleAccountingService::postCompletedSale`, inyectar `TaxLinesBuilder` por constructor (junto al `JournalEntryService` existente). Cambiar la construcción de líneas: la línea de Ventas usa `$net = $withInvoice ? (int)$sale->total - (int)$sale->iva_amount : (int)$sale->total;` como `credit_amount`. El débito Caja/Banco sigue en `$sale->total`. Tras las 2 líneas base (y las de COGS), si `wants_invoice`, hacer `array_push($lines, ...$this->taxLines->saleTaxLines((int)$sale->iva_amount, (int)$sale->it_amount));`.
```php
    // Al inicio del método, tras cargar cuentas:
    $withInvoice = (bool) $sale->wants_invoice;

    // Línea Ventas (crédito): neto si hay factura.
    $ventasCredit = $withInvoice ? ((int) $sale->total - (int) $sale->iva_amount) : (int) $sale->total;
    // ...usar $ventasCredit en la línea de $salesIncomeAccount en vez de (int)$sale->total...

    // Tras armar las líneas base + COGS:
    if ($withInvoice) {
        foreach ($this->taxLines->saleTaxLines((int) $sale->iva_amount, (int) $sale->it_amount) as $tl) {
            $lines[] = $tl + ['reference' => $sale->invoice_number];
        }
    }
```
Mantener el resto (COGS/Inventario, source_type, voucher) igual. La venta rápida (`wants_invoice=false`) queda con el asiento actual.

- [ ] **Step 4: Correr — pasa.** Regresión: `php artisan test --filter "SaleAccounting|SaleTaxPosting|SaleFiscal"`
- [ ] **Step 5: Commit** — `git commit -am "feat(contab): IVA/IT en el asiento de venta con factura (venta rapida intacta)"`

---

## Task 4: Columnas fiscales en compras + `PurchaseTaxCalculator` + wiring

**Files:** Create `database/migrations/2026_08_27_000003_add_fiscal_columns_to_purchases.php`, `app/Fiscal/PurchaseTaxCalculator.php` · Modify `app/Models/Purchase.php`, `app/Services/PurchaseService.php` · Test `tests/Feature/Accounting/PurchaseTaxCalculatorTest.php`

- [ ] **Step 1: Test que falla**
```php
<?php

namespace Tests\Feature\Accounting;

use App\Fiscal\PurchaseTaxCalculator;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseTaxCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_computes_iva_only(): void
    {
        Setting::set('tax_iva_rate', '13');
        $r = app(PurchaseTaxCalculator::class)->forTotal(10000); // 100.00

        $this->assertSame(10000, $r['taxable_base']);
        $this->assertSame(1300, $r['iva_amount']); // 13% de 100.00
    }
}
```
- [ ] **Step 2: Correr — falla**
- [ ] **Step 3: Migración** aditiva `add_fiscal_columns_to_purchases` (mirar `2026_07_20_170200_add_fiscal_columns_to_sales.php` como molde):
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('taxable_base')->default(0);
            $table->unsignedBigInteger('iva_amount')->default(0);
            $table->boolean('wants_invoice')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['taxable_base', 'iva_amount', 'wants_invoice']);
        });
    }
};
```
- [ ] **Step 4: `PurchaseTaxCalculator`** `app/Fiscal/PurchaseTaxCalculator.php`:
```php
<?php

namespace App\Fiscal;

use App\Models\Setting;

/** Desglose fiscal de una compra: solo IVA (crédito fiscal). IT es solo de ventas. Centavos. */
class PurchaseTaxCalculator
{
    /** @return array{taxable_base:int, iva_amount:int} */
    public function forTotal(int $totalCents): array
    {
        $ivaRate = (float) Setting::get('tax_iva_rate', '0');
        return [
            'taxable_base' => max(0, $totalCents),
            'iva_amount'   => (int) round($totalCents * $ivaRate / 100),
        ];
    }
}
```
- [ ] **Step 5: Modelo + Service** — en `app/Models/Purchase.php` agregar `taxable_base, iva_amount, wants_invoice` al `$fillable` y casts (`taxable_base`=>'integer', `iva_amount`=>'integer', `wants_invoice`=>'boolean'). En `app/Services/PurchaseService.php`, READ el método de creación/recepción de compra y, espejando `SaleService.php:165-177`, calcular `app(PurchaseTaxCalculator::class)->forTotal((int)$purchase->total)` y persistir `taxable_base/iva_amount` + `wants_invoice` (desde el input/DTO de la compra; si el flujo de compra aún no captura "quiere factura", default `false` y dejar un TODO — no inventar UI en este task).
- [ ] **Step 6: Correr — pasa** (`php artisan test --filter PurchaseTaxCalculatorTest`)
- [ ] **Step 7: Commit** — `git commit -am "feat(contab): columnas fiscales en compras + PurchaseTaxCalculator + wiring"`

---

## Task 5: CF-IVA en el asiento de compra

**Files:** Modify `app/Services/Accounting/PurchaseAccountingService.php` · Test `tests/Feature/Accounting/PurchaseTaxPostingTest.php`

- [ ] **Step 1: Test que falla** — venta... perdón, compra CON factura: Inventario=`total-iva`, CF-IVA=`iva` (1.1.05), Caja/CxP=`total`; cuadra; sin IT. Y compra SIN factura: 2 líneas actuales. Sembrar cuentas 1.1.04, 1.1.01, 2.1.01, 1.1.05 + settings + period Open + un `Purchase` con `total`, `wants_invoice`, `iva_amount`. READ los tests de compra existentes (`tests/Feature/**Purchase*`) para el armado.

- [ ] **Step 2: Correr — falla**
- [ ] **Step 3: Implementar** — en `PurchaseAccountingService::postPurchase`, inyectar `TaxLinesBuilder` por constructor. La línea Inventario (débito) usa `$net = $withInvoice ? (int)$purchase->total - (int)$purchase->iva_amount : (int)$purchase->total;`. La contrapartida Caja/CxP sigue en `$total`. Si `wants_invoice`, agregar `...$this->taxLines->purchaseTaxLines((int)$purchase->iva_amount)` (con `reference`). Cuadre: Inventario_neto + CF-IVA = total = contrapartida.
```php
    $withInvoice = (bool) $purchase->wants_invoice;
    $inventoryDebit = $withInvoice ? ((int) $purchase->total - (int) $purchase->iva_amount) : (int) $purchase->total;
    // ...línea inventario usa $inventoryDebit...
    if ($withInvoice) {
        foreach ($this->taxLines->purchaseTaxLines((int) $purchase->iva_amount) as $tl) {
            $lines[] = $tl + ['reference' => $purchase->invoice_number];
        }
    }
```
- [ ] **Step 4: Correr — pasa.** Regresión: `php artisan test --filter "Purchase"`
- [ ] **Step 5: Commit** — `git commit -am "feat(contab): CF-IVA en el asiento de compra con factura"`

---

## Task 6: Reporte lee saldos fiscales reales (sin doble-conteo)

**Files:** Modify `app/Services/FinancialStatementService.php` · Test `tests/Feature/Finance/TaxBreakdownRealBalancesTest.php`

- [ ] **Step 1: Test que falla** — postear una venta con factura, construir `FinancialStatementService::build(from, to, withTaxes: true)`, y asertar que `estado_resultados['taxes']['iva_debito']` = el `iva_amount` posteado real (saldo de 2.1.11), no una estimación por %. Y que `total_tax` NO doble-cuente el IT (que ya es gasto). READ `tests/Feature/**FinancialStatement*` o `**FinancialReport*` para el armado.

- [ ] **Step 2: Correr — falla**
- [ ] **Step 3: Implementar** — cambiar la firma a `buildTaxBreakdown(Collection $periodBalances, bool $withTaxes)` y en `buildIncomeStatement` pasar `$periodBalances` (la colección ya está ahí). Leer los saldos reales por código de cuenta desde la colección:
```php
    protected function buildTaxBreakdown(\Illuminate\Support\Collection $periodBalances, bool $withTaxes): array
    {
        $ivaRate = (float) Setting::get('tax_iva_rate', '13');
        $itRate  = (float) Setting::get('tax_it_rate', '3');
        $includeIva = Setting::get('tax_include_iva', '1') === '1';
        $includeIt  = Setting::get('tax_include_it', '1') === '1';

        $dfCode = Setting::get('accounting_df_iva_code', '2.1.11');
        $cfCode = Setting::get('accounting_cf_iva_code', '1.1.05');
        $itPayCode = Setting::get('accounting_it_payable_code', '2.1.12');

        $balanceOf = fn (string $code) => (int) abs((int) (optional($periodBalances->firstWhere('code', $code))->balance ?? 0));

        $base = [
            'include_iva' => $includeIva, 'include_it' => $includeIt,
            'iva_rate' => $ivaRate, 'it_rate' => $itRate,
            'taxable_sales_base' => 0, 'taxable_purchases_base' => 0,
            'iva_debito' => 0, 'iva_credito' => 0, 'iva_determinado' => 0,
            'it_base' => 0, 'it_amount' => 0, 'total_tax' => 0,
        ];
        if (! $withTaxes) {
            return $base;
        }

        $ivaDebito = $includeIva ? $balanceOf($dfCode) : 0;
        $ivaCredito = $includeIva ? $balanceOf($cfCode) : 0;
        $ivaDeterminado = max($ivaDebito - $ivaCredito, 0);
        $itAmount = $includeIt ? $balanceOf($itPayCode) : 0;

        return array_merge($base, [
            'iva_debito' => $ivaDebito,
            'iva_credito' => $ivaCredito,
            'iva_determinado' => $ivaDeterminado,
            'it_amount' => $itAmount,
            // IT ya está registrado como gasto (cuenta 5.2.01) en expense_total, por lo que
            // NO se vuelve a restar en total_tax (evita doble-conteo). total_tax = IVA determinado.
            'total_tax' => $ivaDeterminado,
        ]);
    }
```
NOTA: `$periodBalances` items tienen `->code` y `->balance` (ver `buildIncomeStatement`/`calculateAccountBalances`). El `balance` de una cuenta de pasivo (DF-IVA, IT x pagar) es su saldo acreedor; el de CF-IVA (activo) su saldo deudor. `abs()` los normaliza a positivo. Verificar el signo real de `balance` en `LedgerBalanceService` (ya calcula `balance` según `normal_balance`, así que para estas cuentas será positivo cuando hay saldo) y ajustar si hace falta.

- [ ] **Step 4: Correr — pasa.** Regresión: `php artisan test --filter "FinancialStatement|IncomeStatement|Tax"`
- [ ] **Step 5: Commit** — `git commit -am "fix(contab): el reporte lee saldos fiscales reales sin doble-contar IT"`

---

## Cierre
- [ ] **Suite completa** — `php artisan test`. Verificar sin regresiones (venta/compra existentes, reportes financieros).
- [ ] **Nota de deploy** — `php artisan migrate` (aditiva). El asiento fiscal solo aplica a ventas/compras nuevas con `wants_invoice`. Follow-up: capturar "quiere factura" en el flujo de compra (UI) si aún no existe.
- [ ] **Follow-ups anotados:** plan de cuentas 4-5 niveles (tema B pieza 2); tema C (cierre IUE/reserva/EERR) — que reordenará la semántica del Estado de Resultados (IVA determinado restado del neto, etc.).

Al terminar → **superpowers:finishing-a-development-branch**.

---

## Self-review (checklist del autor)
- **Cobertura de spec:** R1 (T1), R2 (T1), R3 (T2), R4 (T3), R5 (T4), R6 (T5), R7 (T6). ✔
- **Placeholders:** código completo salvo el armado de tests que reusan patrones existentes (Sale/Purchase/period) — el implementador READ el test molde citado. El wiring de `wants_invoice` en compras (T4 step 5) depende del flujo real de `PurchaseService` (el implementador lo lee); default `false` documentado si el flujo no captura factura aún. ✔
- **Consistencia:** `TaxLinesBuilder::saleTaxLines(iva,it)`/`purchaseTaxLines(iva)` usados igual en T3/T5. Settings `accounting_df_iva_code`/`_cf_iva_code`/`_it_payable_code`/`_it_expense_code` idénticos en T1/T2/T6. Neto = `total − iva` en venta (Ventas) y compra (Inventario). ✔
- **Invariante:** venta/compra rápida sin factura → asiento actual intacto (gate `if wants_invoice`), testeado en T3/T5. ✔
- **A verificar en implementación:** columnas NOT NULL de `chart_of_accounts`/`purchases`; grupo padre `5.2` para la cuenta gasto IT; signo de `->balance` para las cuentas fiscales en T6; que `PurchaseService` capture `wants_invoice` (si no, default false + TODO). ✔
