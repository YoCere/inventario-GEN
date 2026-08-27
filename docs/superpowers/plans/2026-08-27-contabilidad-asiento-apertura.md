# Asiento de Apertura Contable — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Registrar un asiento/comprobante de apertura (`entry_type=apertura`) único por gestión, cargado por un wizard con valores autopropuestos, posteable retroactivamente a un período cerrado.

**Architecture:** La apertura es un `JournalEntry` normal — reusa `JournalEntryService` (doble partida, centavos, read-model). Lógica nueva: enums, un DTO de rubros, `OpeningBalanceService` (propone + postea + unicidad), un flag `allowClosedPeriod` en `createPostedEntry` restringido a apertura, y un wizard Livewire. Mapeo de cuentas 100% por Setting.

**Tech Stack:** Laravel 11, PHPUnit class-style + RefreshDatabase, Livewire 3, MySQL. Sin dependencias nuevas.

**Spec:** `docs/superpowers/specs/2026-08-27-contabilidad-asiento-apertura-design.md`

---

## Convenciones (leer antes de empezar)
- **NUNCA `migrate:fresh`/`refresh`** (MySQL dev compartido). Migraciones aditivas.
- Tests: `extends Tests\TestCase`, `use RefreshDatabase`. Correr `php artisan test --filter <Clase>`.
- Dinero en **centavos-int**. Mapeo de cuentas por `Setting::get('accounting_*_code', default)`.
- Patrón de referencia de posteo: `app/Services/Accounting/SaleAccountingService.php` (`findPostingAccount`, `resolveOpenPeriod`, `createPostedEntry`).

## Estado / anclajes verificados
- `JournalEntryService::createPostedEntry(payload, lines)` valida cuadre + prohíbe período cerrado (`app/Services/Accounting/JournalEntryService.php:42-102`). `findPostedSourceEntry(sourceType,sourceId)` (`:104-112`). `reverseEntry` (`:114-159`).
- Enums: `app/Enums/JournalEntryType.php` (Normal/Ajuste), `app/Enums/VoucherType.php` (Ingreso/Egreso/Traspaso).
- Cuentas sembradas: Caja 1.1.01, Banco 1.1.02, CxC 1.1.03, Inventario 1.1.04, PPE 1.2.01, Dep.Acum 1.2.02, CxP 2.1.01, Capital 3.1 (`database/seeders/ChartOfAccountSeeder.php`).
- Derivación: `FixedAsset` (`acquisition_cost` int, `accumulated_depreciation` int, `is_opening` bool). `Loan` (`outstanding_balance` int, `is_opening` bool). Inventario = Σ `product_stocks.quantity × products.purchase_price` (ambos int; `purchase_price` en centavos).
- `AccountingPeriod` (`start_date` date, `resolveOpenForDate($date)`). Primer período de gestión = `whereYear('start_date',$year)->orderBy('start_date')->first()`.

## File Structure
- Modify `app/Enums/JournalEntryType.php`, `app/Enums/VoucherType.php` — caso Apertura.
- Create `database/migrations/2026_08_27_000001_seed_opening_account_codes.php` — settings de mapeo.
- Modify `app/Services/Accounting/JournalEntryService.php` — flag `allowClosedPeriod` + bloqueo reverso apertura.
- Create `app/DTOs/OpeningBalanceData.php` — rubros en centavos.
- Create `app/Services/Accounting/OpeningBalanceService.php` — propose + post + unicidad.
- Create `app/Livewire/Accounting/OpeningBalanceWizard.php` + `resources/views/livewire/accounting/opening-balance-wizard.blade.php` + ruta.
- Tests bajo `tests/Feature/Accounting/`.

---

## Task 1: Enums Apertura

**Files:** Modify `app/Enums/JournalEntryType.php`, `app/Enums/VoucherType.php` · Test `tests/Feature/Accounting/OpeningEnumsTest.php`

- [ ] **Step 1: Test que falla**
```php
<?php

namespace Tests\Feature\Accounting;

use App\Enums\JournalEntryType;
use App\Enums\VoucherType;
use Tests\TestCase;

class OpeningEnumsTest extends TestCase
{
    public function test_apertura_cases_exist(): void
    {
        $this->assertSame('apertura', JournalEntryType::Apertura->value);
        $this->assertSame('Apertura', JournalEntryType::Apertura->label());
        $this->assertSame('apertura', VoucherType::Apertura->value);
        $this->assertSame('APERTURA', VoucherType::Apertura->shortLabel());
    }
}
```
- [ ] **Step 2: Correr — falla** (`php artisan test --filter OpeningEnumsTest`)
- [ ] **Step 3: Implementar**

`JournalEntryType.php`: agregar `case Apertura = 'apertura';` y en `label()` el brazo `self::Apertura => 'Apertura',`.
`VoucherType.php`: agregar `case Apertura = 'apertura';`, en `label()` `self::Apertura => 'Comprobante de Apertura',` y en `shortLabel()` `self::Apertura => 'APERTURA',`.

- [ ] **Step 4: Correr — pasa**
- [ ] **Step 5: Commit** — `git commit -am "feat(contab): enum Apertura en JournalEntryType y VoucherType"`

---

## Task 2: Settings de mapeo de cuentas de apertura

**Files:** Create `database/migrations/2026_08_27_000001_seed_opening_account_codes.php` · Test `tests/Feature/Accounting/OpeningAccountSettingsTest.php`

- [ ] **Step 1: Test que falla**
```php
<?php

namespace Tests\Feature\Accounting;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpeningAccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_account_codes_are_seeded(): void
    {
        $this->assertSame('1.1.01', Setting::get('accounting_opening_cash_code'));
        $this->assertSame('1.1.04', Setting::get('accounting_opening_inventory_code'));
        $this->assertSame('3.1', Setting::get('accounting_opening_capital_code'));
        $this->assertSame('1.2.02', Setting::get('accounting_opening_depreciation_code'));
    }
}
```
- [ ] **Step 2: Correr — falla**
- [ ] **Step 3: Implementar migración** (patrón idempotente de `2026_05_21_120000`)
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            'accounting_opening_cash_code'         => '1.1.01', // Caja
            'accounting_opening_bank_code'         => '1.1.02', // Banco
            'accounting_opening_receivable_code'   => '1.1.03', // Cuentas por cobrar
            'accounting_opening_inventory_code'    => '1.1.04', // Inventario
            'accounting_opening_ppe_code'          => '1.2.01', // PPE / Muebles y enseres
            'accounting_opening_depreciation_code' => '1.2.02', // Depreciación acumulada
            'accounting_opening_payable_code'      => '2.1.01', // Cuentas por pagar
            'accounting_opening_loan_code'         => '2.1.01', // Préstamos (configurable; default = CxP)
            'accounting_opening_capital_code'      => '3.1',    // Capital Social
        ];

        foreach ($defaults as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert([
                    'key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'accounting_opening_cash_code', 'accounting_opening_bank_code',
            'accounting_opening_receivable_code', 'accounting_opening_inventory_code',
            'accounting_opening_ppe_code', 'accounting_opening_depreciation_code',
            'accounting_opening_payable_code', 'accounting_opening_loan_code',
            'accounting_opening_capital_code',
        ])->delete();
    }
};
```
- [ ] **Step 4: Correr — pasa**
- [ ] **Step 5: Commit** — `git commit -am "feat(contab): settings de mapeo de cuentas de apertura"`

---

## Task 3: `allowClosedPeriod` en createPostedEntry + bloqueo reverso apertura

**Files:** Modify `app/Services/Accounting/JournalEntryService.php` · Test `tests/Feature/Accounting/ClosedPeriodOpeningTest.php`

- [ ] **Step 1: Test que falla**
```php
<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountingPeriodStatus;
use App\Enums\JournalEntryType;
use App\Enums\VoucherType;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ClosedPeriodOpeningTest extends TestCase
{
    use RefreshDatabase;

    private function seedCoa(): array
    {
        $caja = ChartOfAccount::create(['code' => '1.1.01', 'name' => 'Caja', 'level' => 3, 'account_type' => 'asset', 'normal_balance' => 'debit', 'allows_posting' => true, 'is_active' => true]);
        $cap  = ChartOfAccount::create(['code' => '3.1', 'name' => 'Capital', 'level' => 2, 'account_type' => 'equity', 'normal_balance' => 'credit', 'allows_posting' => true, 'is_active' => true]);
        return [$caja, $cap];
    }

    public function test_opening_can_post_to_closed_period_with_flag(): void
    {
        [$caja, $cap] = $this->seedCoa();
        $user = User::factory()->create();
        $period = AccountingPeriod::create(['name' => 'Gestion 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Closed]);

        $entry = app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-01-01', 'accounting_period_id' => $period->id,
            'entry_type' => JournalEntryType::Apertura->value, 'voucher_type' => VoucherType::Apertura->value,
            'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $caja->id, 'debit_amount' => 100000],
            ['chart_of_account_id' => $cap->id, 'credit_amount' => 100000],
        ], allowClosedPeriod: true);

        $this->assertSame('apertura', $entry->entry_type);
    }

    public function test_normal_entry_still_blocked_on_closed_period(): void
    {
        [$caja, $cap] = $this->seedCoa();
        $user = User::factory()->create();
        $period = AccountingPeriod::create(['name' => 'Gestion 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Closed]);

        $this->expectException(RuntimeException::class);
        app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-01-01', 'accounting_period_id' => $period->id, 'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $caja->id, 'debit_amount' => 100000],
            ['chart_of_account_id' => $cap->id, 'credit_amount' => 100000],
        ]);
    }

    public function test_allow_closed_period_only_for_apertura(): void
    {
        [$caja, $cap] = $this->seedCoa();
        $user = User::factory()->create();
        $period = AccountingPeriod::create(['name' => 'Gestion 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Closed]);

        $this->expectException(RuntimeException::class);
        app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-01-01', 'accounting_period_id' => $period->id,
            'entry_type' => JournalEntryType::Normal->value, 'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $caja->id, 'debit_amount' => 100000],
            ['chart_of_account_id' => $cap->id, 'credit_amount' => 100000],
        ], allowClosedPeriod: true);
    }
}
```
- [ ] **Step 2: Correr — falla**
- [ ] **Step 3: Implementar**

En `JournalEntryService::createPostedEntry`, cambiar la firma a:
```php
    public function createPostedEntry(array $payload, array $lines, bool $allowClosedPeriod = false): JournalEntry
```
Reemplazar el bloque del check de período cerrado (`:52-56`) por:
```php
            $entryTypeCheck = $payload['entry_type'] ?? JournalEntryType::Normal->value;
            if ($entryTypeCheck instanceof JournalEntryType) {
                $entryTypeCheck = $entryTypeCheck->value;
            }

            if ($allowClosedPeriod && $entryTypeCheck !== JournalEntryType::Apertura->value) {
                throw new RuntimeException('allowClosedPeriod solo es válido para asientos de apertura.');
            }

            if (! $allowClosedPeriod && $period->status !== AccountingPeriodStatus::Open) {
                throw new RuntimeException(
                    "No se puede postear al periodo '{$period->name}' (status: {$period->status->label()})."
                );
            }
```
En `reverseEntry`, al inicio (después del check de `Posted`, `:116-118`), agregar:
```php
        if ($entry->entry_type === JournalEntryType::Apertura->value) {
            throw new RuntimeException('El asiento de apertura no puede revertirse.');
        }
```
- [ ] **Step 4: Correr — pasa**
- [ ] **Step 5: Commit** — `git commit -am "feat(contab): allowClosedPeriod para apertura + bloqueo de reverso"`

---

## Task 4: DTO `OpeningBalanceData`

**Files:** Create `app/DTOs/OpeningBalanceData.php` · Test `tests/Feature/Accounting/OpeningBalanceDataTest.php`

- [ ] **Step 1: Test que falla**
```php
<?php

namespace Tests\Feature\Accounting;

use App\DTOs\OpeningBalanceData;
use Tests\TestCase;

class OpeningBalanceDataTest extends TestCase
{
    public function test_from_array_and_totals(): void
    {
        $d = OpeningBalanceData::fromArray([
            'cash' => 18000000, 'receivable' => 1500000, 'inventory' => 9050000, 'ppe' => 3500000,
            'payable' => 1000000, 'capital' => 31050000,
        ]);

        $this->assertSame(18000000, $d->cash);
        $this->assertSame(0, $d->bank);
        $this->assertSame(32050000, $d->totalAssets());       // 180000+15000+90500+35000 (·100)
        $this->assertSame(32050000, $d->totalLiabilitiesAndEquity()); // 10000 + 310500 (·100) + 0 dep
    }
}
```
- [ ] **Step 2: Correr — falla**
- [ ] **Step 3: Implementar**
```php
<?php

namespace App\DTOs;

/**
 * Rubros del asiento de apertura, todos en centavos-int.
 * Activos: cash, bank, receivable, inventory, ppe.
 * Contra-activo: accumulated_depreciation (resta del activo).
 * Pasivos: payable, loans. Patrimonio: capital.
 */
class OpeningBalanceData
{
    public function __construct(
        public readonly int $cash = 0,
        public readonly int $bank = 0,
        public readonly int $receivable = 0,
        public readonly int $inventory = 0,
        public readonly int $ppe = 0,
        public readonly int $accumulated_depreciation = 0,
        public readonly int $payable = 0,
        public readonly int $loans = 0,
        public readonly int $capital = 0,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self(
            cash: (int) ($d['cash'] ?? 0),
            bank: (int) ($d['bank'] ?? 0),
            receivable: (int) ($d['receivable'] ?? 0),
            inventory: (int) ($d['inventory'] ?? 0),
            ppe: (int) ($d['ppe'] ?? 0),
            accumulated_depreciation: (int) ($d['accumulated_depreciation'] ?? 0),
            payable: (int) ($d['payable'] ?? 0),
            loans: (int) ($d['loans'] ?? 0),
            capital: (int) ($d['capital'] ?? 0),
        );
    }

    /** Activo neto = activos brutos − depreciación acumulada. */
    public function totalAssets(): int
    {
        return $this->cash + $this->bank + $this->receivable + $this->inventory + $this->ppe
            - $this->accumulated_depreciation;
    }

    public function totalLiabilitiesAndEquity(): int
    {
        return $this->payable + $this->loans + $this->capital;
    }

    /** Capital que hace cuadrar el asiento (plug). */
    public function balancingCapital(): int
    {
        return ($this->cash + $this->bank + $this->receivable + $this->inventory + $this->ppe)
            - $this->accumulated_depreciation - $this->payable - $this->loans;
    }
}
```
NOTA: el test compara `totalAssets()` (activo neto, dep=0 en el caso) con `totalLiabilitiesAndEquity()`. Con dep=0 ambos = 32.050.000.
- [ ] **Step 4: Correr — pasa**
- [ ] **Step 5: Commit** — `git commit -am "feat(contab): DTO OpeningBalanceData"`

---

## Task 5: `OpeningBalanceService::propose` (autoderivación)

**Files:** Create `app/Services/Accounting/OpeningBalanceService.php` · Test `tests/Feature/Accounting/OpeningBalanceProposeTest.php`

- [ ] **Step 1: Test que falla**
```php
<?php

namespace Tests\Feature\Accounting;

use App\Models\FixedAsset;
use App\Models\Loan;
use App\Services\Accounting\OpeningBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpeningBalanceProposeTest extends TestCase
{
    use RefreshDatabase;

    public function test_propose_derives_ppe_loans_and_capital_plug(): void
    {
        FixedAsset::create(['code' => 'AF1', 'name' => 'Mueble', 'acquisition_date' => '2026-01-01', 'acquisition_cost' => 3500000, 'accumulated_depreciation' => 0, 'is_opening' => true]);
        Loan::create(['lender' => 'Banco X', 'code' => 'PR1', 'principal' => 1000000, 'start_date' => '2026-01-01', 'status' => 'active', 'is_opening' => true, 'outstanding_balance' => 1000000]);

        $data = app(OpeningBalanceService::class)->propose('2026-01-01');

        $this->assertSame(3500000, $data->ppe);
        $this->assertSame(1000000, $data->loans);
        // capital plug = activos(ppe 3.500.000) - pasivos(loans 1.000.000) = 2.500.000
        $this->assertSame(2500000, $data->capital);
    }
}
```
(Si `FixedAsset`/`Loan` requieren columnas adicionales no-nullable, agregarlas al `create()` según su migración.)
- [ ] **Step 2: Correr — falla**
- [ ] **Step 3: Implementar (solo `propose` por ahora)**
```php
<?php

namespace App\Services\Accounting;

use App\DTOs\OpeningBalanceData;
use App\Models\FixedAsset;
use App\Models\Loan;
use Illuminate\Support\Facades\DB;

class OpeningBalanceService
{
    public function __construct(protected JournalEntryService $journalEntryService) {}

    /** Autopropone saldos iniciales; Caja/Banco/CxC/CxP quedan en 0 para que el usuario los llene. */
    public function propose(string $date): OpeningBalanceData
    {
        $inventory = (int) DB::table('product_stocks')
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->sum(DB::raw('product_stocks.quantity * products.purchase_price'));

        $ppe = (int) FixedAsset::where('is_opening', true)->sum('acquisition_cost');
        $depreciation = (int) FixedAsset::where('is_opening', true)->sum('accumulated_depreciation');
        $loans = (int) Loan::where('is_opening', true)->sum('outstanding_balance');

        $partial = new OpeningBalanceData(
            cash: 0, bank: 0, receivable: 0,
            inventory: $inventory, ppe: $ppe, accumulated_depreciation: $depreciation,
            payable: 0, loans: $loans, capital: 0,
        );

        // Capital = plug que cuadra el asiento con los rubros propuestos.
        return new OpeningBalanceData(
            cash: 0, bank: 0, receivable: 0,
            inventory: $inventory, ppe: $ppe, accumulated_depreciation: $depreciation,
            payable: 0, loans: $loans, capital: $partial->balancingCapital(),
        );
    }
}
```
- [ ] **Step 4: Correr — pasa**
- [ ] **Step 5: Commit** — `git commit -am "feat(contab): OpeningBalanceService::propose con autoderivación"`

---

## Task 6: `OpeningBalanceService::post` (líneas + unicidad + path apertura)

**Files:** Modify `app/Services/Accounting/OpeningBalanceService.php` · Test `tests/Feature/Accounting/OpeningBalancePostTest.php`

- [ ] **Step 1: Test que falla**
```php
<?php

namespace Tests\Feature\Accounting;

use App\DTOs\OpeningBalanceData;
use App\Enums\AccountingPeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\Accounting\LedgerBalanceService;
use App\Services\Accounting\OpeningBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class OpeningBalancePostTest extends TestCase
{
    use RefreshDatabase;

    private function seed(): void
    {
        foreach ([
            ['1.1.01', 'Caja', 'asset', 'debit'], ['1.1.03', 'CxC', 'asset', 'debit'],
            ['1.1.04', 'Inventario', 'asset', 'debit'], ['1.2.01', 'PPE', 'asset', 'debit'],
            ['2.1.01', 'CxP', 'liability', 'credit'], ['3.1', 'Capital', 'equity', 'credit'],
        ] as [$code, $name, $type, $nb]) {
            ChartOfAccount::create(['code' => $code, 'name' => $name, 'level' => 3, 'account_type' => $type, 'normal_balance' => $nb, 'allows_posting' => true, 'is_active' => true]);
        }
        AccountingPeriod::create(['name' => 'Gestion 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Closed]);
    }

    private function data(): OpeningBalanceData
    {
        // 180.000 Caja + 15.000 CxC + 90.500 Inv + 35.000 PPE = 320.500 activo
        // 10.000 CxP + 310.500 Capital = 320.500  (todo ·100)
        return OpeningBalanceData::fromArray([
            'cash' => 18000000, 'receivable' => 1500000, 'inventory' => 9050000, 'ppe' => 3500000,
            'payable' => 1000000, 'capital' => 31050000,
        ]);
    }

    public function test_posts_balanced_opening_to_closed_period(): void
    {
        $this->seed();
        $user = User::factory()->create();

        $entry = app(OpeningBalanceService::class)->post($this->data(), '2026-01-01', $user->id);

        $this->assertSame('apertura', $entry->entry_type);
        $this->assertSame(32050000, (int) $entry->lines->sum('debit_amount'));
        $this->assertSame(32050000, (int) $entry->lines->sum('credit_amount'));

        $balances = app(LedgerBalanceService::class)->balancesAt('2026-01-01');
        $caja = $balances->firstWhere('code', '1.1.01');
        $this->assertSame(18000000, (int) $caja['debit'] - (int) $caja['credit']);
    }

    public function test_second_opening_same_gestion_throws(): void
    {
        $this->seed();
        $user = User::factory()->create();
        $service = app(OpeningBalanceService::class);
        $service->post($this->data(), '2026-01-01', $user->id);

        $this->expectException(RuntimeException::class);
        $service->post($this->data(), '2026-01-05', $user->id);
    }

    public function test_negative_capital_rejected(): void
    {
        $this->seed();
        $user = User::factory()->create();
        $bad = OpeningBalanceData::fromArray(['cash' => 100000, 'payable' => 500000, 'capital' => -400000]);

        $this->expectException(RuntimeException::class);
        app(OpeningBalanceService::class)->post($bad, '2026-01-01', $user->id);
    }
}
```
NOTA: verificar en `LedgerBalanceService::balancesAt()` las claves reales del array (`code`/`debit`/`credit`); ajustar los asserts a los nombres que devuelve (ver `app/Services/Accounting/LedgerBalanceService.php`).
- [ ] **Step 2: Correr — falla**
- [ ] **Step 3: Implementar `post` + helpers**

Agregar imports en `OpeningBalanceService.php`: `use App\Enums\JournalEntryType; use App\Enums\VoucherType; use App\Models\AccountingPeriod; use App\Models\ChartOfAccount; use App\Models\Setting; use Carbon\Carbon; use RuntimeException;` y los métodos:
```php
    public function post(OpeningBalanceData $data, string $date, int $userId): \App\Models\JournalEntry
    {
        if ($data->capital < 0) {
            throw new RuntimeException('El capital de apertura no puede ser negativo (los pasivos superan a los activos).');
        }

        return DB::transaction(function () use ($data, $date, $userId) {
            $year = Carbon::parse($date)->year;
            $period = AccountingPeriod::whereYear('start_date', $year)
                ->orderBy('start_date')
                ->lockForUpdate()
                ->first();

            if (! $period) {
                throw new RuntimeException("No existe período contable para la gestión {$year}.");
            }

            if ($this->journalEntryService->findPostedSourceEntry(AccountingPeriod::class, $period->id)) {
                throw new RuntimeException("Ya existe un asiento de apertura para la gestión {$year}.");
            }

            return $this->journalEntryService->createPostedEntry([
                'entry_date'           => $date,
                'accounting_period_id' => $period->id,
                'description'          => "Asiento de apertura gestión {$year}",
                'source_type'          => AccountingPeriod::class,
                'source_id'            => $period->id,
                'voucher_type'         => VoucherType::Apertura->value,
                'entry_type'           => JournalEntryType::Apertura->value,
                'created_by'           => $userId,
                'posted_by'            => $userId,
            ], $this->buildLines($data), allowClosedPeriod: true);
        });
    }

    /** @return array<int, array{chart_of_account_id:int,debit_amount?:int,credit_amount?:int,description:string}> */
    private function buildLines(OpeningBalanceData $data): array
    {
        // [setting_key, monto, lado, glosa]. lado: 'debit'|'credit'.
        $spec = [
            ['accounting_opening_cash_code',         $data->cash,                     'debit',  'Caja'],
            ['accounting_opening_bank_code',         $data->bank,                     'debit',  'Banco'],
            ['accounting_opening_receivable_code',   $data->receivable,               'debit',  'Cuentas por cobrar'],
            ['accounting_opening_inventory_code',    $data->inventory,                'debit',  'Inventario'],
            ['accounting_opening_ppe_code',          $data->ppe,                      'debit',  'Bienes de uso'],
            ['accounting_opening_depreciation_code', $data->accumulated_depreciation, 'credit', 'Depreciación acumulada'],
            ['accounting_opening_payable_code',      $data->payable,                  'credit', 'Cuentas por pagar'],
            ['accounting_opening_loan_code',         $data->loans,                    'credit', 'Préstamos por pagar'],
            ['accounting_opening_capital_code',      $data->capital,                  'credit', 'Capital social'],
        ];

        $lines = [];
        foreach ($spec as [$settingKey, $amount, $side, $glosa]) {
            if ($amount <= 0) {
                continue; // validateLines exige débito XOR crédito y montos > 0
            }
            $account = $this->findPostingAccount(Setting::get($settingKey));
            $lines[] = [
                'chart_of_account_id' => $account->id,
                'description'         => 'Apertura: ' . $glosa,
                'debit_amount'        => $side === 'debit' ? $amount : 0,
                'credit_amount'       => $side === 'credit' ? $amount : 0,
            ];
        }

        return $lines;
    }

    private function findPostingAccount(string $code): ChartOfAccount
    {
        $account = ChartOfAccount::query()
            ->where('code', $code)->where('is_active', true)->where('allows_posting', true)->first();

        if (! $account) {
            throw new RuntimeException("No existe cuenta contable activa/imputable con código {$code}.");
        }

        return $account;
    }
```
- [ ] **Step 4: Correr — pasa** (`php artisan test --filter OpeningBalancePostTest`)
- [ ] **Step 5: Commit** — `git commit -am "feat(contab): OpeningBalanceService::post con unicidad por gestion y path apertura"`

---

## Task 7: Wizard Livewire `OpeningBalanceWizard`

**Files:** Create `app/Livewire/Accounting/OpeningBalanceWizard.php`, `resources/views/livewire/accounting/opening-balance-wizard.blade.php` · Modify `routes/web.php` (ruta admin) · Test `tests/Feature/Accounting/OpeningBalanceWizardTest.php`

READ FIRST: `app/Livewire/FinanceJournalEntries/ManualJournalEntryForm.php` (patrón de conversión bolivianos→centavos y estructura del componente) y cómo se registran rutas de contabilidad en `routes/web.php`.

- [ ] **Step 1: Test que falla**
```php
<?php

namespace Tests\Feature\Accounting;

use App\Enums\AccountingPeriodStatus;
use App\Livewire\Accounting\OpeningBalanceWizard;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OpeningBalanceWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_post_opening_via_wizard(): void
    {
        foreach ([['1.1.01', 'Caja', 'asset', 'debit'], ['3.1', 'Capital', 'equity', 'credit']] as [$c, $n, $t, $nb]) {
            ChartOfAccount::create(['code' => $c, 'name' => $n, 'level' => 3, 'account_type' => $t, 'normal_balance' => $nb, 'allows_posting' => true, 'is_active' => true]);
        }
        AccountingPeriod::create(['name' => 'Gestion 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Closed]);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(OpeningBalanceWizard::class)
            ->set('date', '2026-01-01')
            ->set('cash', 1800)      // bolivianos en el form
            ->set('capital', 1800)
            ->call('save')
            ->assertHasNoErrors();

        $entry = JournalEntry::where('entry_type', 'apertura')->firstOrFail();
        $this->assertSame(180000, (int) $entry->lines->sum('debit_amount')); // 1800 Bs → 180000 centavos
    }
}
```
- [ ] **Step 2: Correr — falla**
- [ ] **Step 3: Implementar componente**
```php
<?php

namespace App\Livewire\Accounting;

use App\DTOs\OpeningBalanceData;
use App\Services\Accounting\OpeningBalanceService;
use Livewire\Component;

class OpeningBalanceWizard extends Component
{
    public string $date = '';
    // Todos los campos en BOLIVIANOS en el form; se multiplican ×100 al postear.
    public $cash = 0, $bank = 0, $receivable = 0, $inventory = 0, $ppe = 0,
           $accumulated_depreciation = 0, $payable = 0, $loans = 0, $capital = 0;

    public function mount(OpeningBalanceService $service): void
    {
        abort_if(! auth()->user()?->isAdmin(), 403);
        $this->date = now()->startOfYear()->toDateString();
        $p = $service->propose($this->date);
        // Prefill autoderivado (centavos → bolivianos para el form).
        $this->inventory = $p->inventory / 100;
        $this->ppe = $p->ppe / 100;
        $this->accumulated_depreciation = $p->accumulated_depreciation / 100;
        $this->loans = $p->loans / 100;
        $this->capital = $p->capital / 100;
    }

    /** Capital plug en vivo para el preview (bolivianos). */
    public function getBalancingCapitalProperty(): float
    {
        return ($this->cash + $this->bank + $this->receivable + $this->inventory + $this->ppe)
            - $this->accumulated_depreciation - $this->payable - $this->loans;
    }

    public function save(OpeningBalanceService $service): void
    {
        abort_if(! auth()->user()?->isAdmin(), 403);
        $this->validate(['date' => 'required|date']);

        $toCents = fn ($v) => (int) round(((float) $v) * 100);
        $data = OpeningBalanceData::fromArray([
            'cash' => $toCents($this->cash), 'bank' => $toCents($this->bank),
            'receivable' => $toCents($this->receivable), 'inventory' => $toCents($this->inventory),
            'ppe' => $toCents($this->ppe), 'accumulated_depreciation' => $toCents($this->accumulated_depreciation),
            'payable' => $toCents($this->payable), 'loans' => $toCents($this->loans),
            'capital' => $toCents($this->capital),
        ]);

        try {
            $service->post($data, $this->date, auth()->id());
            session()->flash('ok', 'Asiento de apertura registrado.');
            $this->dispatch('toast', message: 'Apertura registrada.', type: 'success');
        } catch (\Throwable $e) {
            $this->addError('date', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.accounting.opening-balance-wizard');
    }
}
```
- [ ] **Step 4: Implementar vista** `resources/views/livewire/accounting/opening-balance-wizard.blade.php` — espejar el estilo de `manual-journal-entry-form.blade.php`. Mínimo funcional: un input `date` (wire:model="date"), un `number` por cada rubro (`wire:model.live="cash"`, bank, receivable, inventory, ppe, accumulated_depreciation, payable, loans, capital), un bloque que muestre `{{ $this->balancingCapital }}` como "Capital sugerido", y un botón `wire:click="save"`. Mostrar `@error('date')`. Envolver en el layout admin habitual.
- [ ] **Step 5: Ruta** — en `routes/web.php`, dentro del grupo admin/contabilidad, agregar:
```php
Route::get('contabilidad/apertura', \App\Livewire\Accounting\OpeningBalanceWizard::class)
    ->middleware('admin')->name('accounting.opening.index');
```
(Ajustar el prefijo/middleware al grupo contable existente.)
- [ ] **Step 6: Correr — pasa** (`php artisan test --filter OpeningBalanceWizardTest`)
- [ ] **Step 7: Commit** — `git commit -am "feat(contab): wizard Livewire de asiento de apertura"`

---

## Cierre
- [ ] **Suite completa** — `php artisan test`. Sin regresiones (todo código nuevo salvo el flag opcional en `createPostedEntry` y el bloqueo de reverso — ambos aditivos).
- [ ] **Nota de deploy** — `php artisan migrate` (aditiva). El wizard vive en `contabilidad/apertura` (admin). El backfill se hace desde el wizard: prellenado autoderivado, el usuario completa Caja/Banco/CxC/CxP, confirma. Advertir manualmente si ya hubo compras retroactivas que movieron inventario.
- [ ] **Follow-ups anotados:** jubilar `opening_balance_amount` (lectores ROI/VAN en `FinancialStatementService`/`BudgetProjectionService`); menú de acceso al wizard; aviso automático de doble-conteo de inventario (R9). Temas B (multinivel+IVA/IT) y C (cierre) siguen.

Al terminar → **superpowers:finishing-a-development-branch**.

---

## Self-review (checklist del autor)
- **Cobertura de spec:** R1 (T1), R2 (T4), R3 (T5), R4 (T6), R5 (T2), R6 (T6 unicidad + source anchor), R7 (T3 flag), R8 (T7 wizard), R9 (T5 autoderivación + nota de aviso en cierre — el aviso automático queda como follow-up explícito), R10 (T3 bloqueo reverso). ✔
- **Placeholders:** código completo en T1-T7 backend. La vista blade (T7 step 4) se especifica por campos + patrón a espejar (el implementador lee `manual-journal-entry-form.blade.php`) — es UI, no lógica; aceptable. ✔
- **Consistencia de tipos:** `OpeningBalanceData` (9 rubros) idéntico en DTO/propose/post/wizard. `createPostedEntry(..., bool $allowClosedPeriod=false)` usado igual en T3 test y T6. `findPostedSourceEntry(AccountingPeriod::class, period->id)` para unicidad. `VoucherType::Apertura`/`JournalEntryType::Apertura` consistentes. ✔
- **Riesgo:** el flag `allowClosedPeriod` está restringido a `entry_type=apertura` (T3) — no puede abusarse para postear normal a período cerrado. Capital negativo rechazado. Reverso bloqueado. Venta/compra no tocadas. ✔
- **A verificar en implementación:** claves reales del array de `LedgerBalanceService::balancesAt()` (asserts de T6); columnas no-nullable de `FixedAsset`/`Loan` al crearlos en tests; código de cuenta para préstamos (default configurable = CxP 2.1.01). ✔
