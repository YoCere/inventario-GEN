# Cierre de Gestión (IUE + Reserva Legal) — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Una acción admin "Cerrar gestión" que muestra un preview y postea la provisión de IUE + reserva legal (calculadas por C1) como un asiento `entry_type=cierre`, idempotente por gestión.

**Architecture:** Reusa `JournalEntryService` y `FinancialStatementService` (C1, fuente de los montos). Lógica nueva en `GestionCierreService` (preview + close + unicidad) y un wizard Livewire. Cuentas por Setting. Un solo asiento con las líneas que apliquen (IUE y/o reserva), anclado a la gestión.

**Tech Stack:** Laravel 11, PHPUnit class-style + RefreshDatabase, Livewire 3, MySQL. Sin dependencias nuevas.

**Spec:** `docs/superpowers/specs/2026-09-01-contabilidad-cierre-gestion-design.md`

---

## Convenciones
- **NUNCA `migrate:fresh`**. Migraciones aditivas, seeders idempotentes (`updateOrCreate`). Cuentas contables SOLO en `ChartOfAccountSeeder`, NUNCA en migración (evita huérfanos — lección de 6.7).
- Dinero en **centavos-int**. Cuentas por `Setting::get('accounting_*_code', default)`.
- Tests: `extends Tests\TestCase`, `use RefreshDatabase`. Correr `php artisan test --filter <Clase>`.

## Anclajes verificados
- `JournalEntryService::createPostedEntry(array $payload, array $lines, bool $allowClosedPeriod=false)` — el guard actual (post-apertura) hace: `if ($allowClosedPeriod && $entryTypeCheck !== JournalEntryType::Apertura->value) throw` y `if (!$allowClosedPeriod && $period->status !== Open) throw`. `findPostedSourceEntry(sourceType,sourceId)`. `reverseEntry` bloquea `apertura` al inicio.
- `FinancialStatementService::build($from,$to,withTaxes)` → `['estado_resultados']` con `iue` (int), `reserva_legal` (int), `utilidad_antes_impuestos` (int). (C1, en main.)
- Enums `JournalEntryType` (Normal/Ajuste/Apertura), `VoucherType` (Ingreso/Egreso/Traspaso/Apertura). `JournalEntry::scopeMovimientos` = `whereIn('entry_type',[Normal->value, Apertura->value])`.
- Cuentas existentes: `3.3 Resultado del Ejercicio` (equity), `3.1`, `3.2`. Faltan `2.1.13`, `6.8`, `3.4`.
- Patrón de servicio+wizard de apertura: `app/Services/Accounting/OpeningBalanceService.php`, `app/Livewire/Accounting/OpeningBalanceWizard.php`, ruta `contabilidad/apertura`.
- Menú/hub Contabilidad: `resources/views/finance/hubs/contabilidad.blade.php` (tarjetas).

## File Structure
- Modify `app/Enums/JournalEntryType.php`, `app/Enums/VoucherType.php`, `app/Models/JournalEntry.php`, `app/Services/Accounting/JournalEntryService.php`.
- Modify `database/seeders/ChartOfAccountSeeder.php`. Create `database/migrations/2026_09_01_000002_seed_closing_account_codes.php`.
- Create `app/Services/Accounting/GestionCierreService.php`.
- Create `app/Livewire/Accounting/GestionCierreWizard.php` + `resources/views/livewire/accounting/gestion-cierre-wizard.blade.php` + wrapper view + ruta. Modify hub Contabilidad.
- Tests en `tests/Feature/Accounting/`.

---

## Task 1: Enum Cierre + scope + allowClosedPeriod + bloqueo reverso

**Files:** Modify `app/Enums/JournalEntryType.php`, `app/Enums/VoucherType.php`, `app/Models/JournalEntry.php`, `app/Services/Accounting/JournalEntryService.php` · Test `tests/Feature/Accounting/ClosingEntryTypeTest.php`

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

class ClosingEntryTypeTest extends TestCase
{
    use RefreshDatabase;

    private function seed(): array
    {
        $a = ChartOfAccount::create(['code' => '6.8', 'name' => 'IUE', 'level' => 2, 'account_type' => 'expense', 'normal_balance' => 'debit', 'allows_posting' => true, 'is_active' => true]);
        $b = ChartOfAccount::create(['code' => '2.1.13', 'name' => 'IUE x Pagar', 'level' => 3, 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => true, 'is_active' => true]);
        return [$a, $b];
    }

    public function test_cierre_puede_postear_a_periodo_cerrado(): void
    {
        [$a, $b] = $this->seed();
        $user = User::factory()->create();
        $period = AccountingPeriod::create(['name' => 'G2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Closed]);

        $entry = app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-12-31', 'accounting_period_id' => $period->id,
            'entry_type' => JournalEntryType::Cierre->value, 'voucher_type' => VoucherType::Cierre->value,
            'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $a->id, 'debit_amount' => 100000],
            ['chart_of_account_id' => $b->id, 'credit_amount' => 100000],
        ], allowClosedPeriod: true);

        $this->assertSame('cierre', $entry->entry_type->value);
    }

    public function test_reverso_de_cierre_bloqueado(): void
    {
        [$a, $b] = $this->seed();
        $user = User::factory()->create();
        $period = AccountingPeriod::create(['name' => 'G2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Open]);
        $entry = app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-12-31', 'accounting_period_id' => $period->id,
            'entry_type' => JournalEntryType::Cierre->value, 'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => $a->id, 'debit_amount' => 100000],
            ['chart_of_account_id' => $b->id, 'credit_amount' => 100000],
        ]);

        $this->expectException(RuntimeException::class);
        app(JournalEntryService::class)->reverseEntry($entry, $user->id);
    }
}
```
NOTA: `entry_type` castea a enum → assert con `->value`. Ajustar columnas NOT NULL de ChartOfAccount/AccountingPeriod si hace falta.

- [ ] **Step 2: Correr — falla**

- [ ] **Step 3: Implementar**
- `app/Enums/JournalEntryType.php`: agregar `case Cierre = 'cierre';` y en `label()` el brazo `self::Cierre => 'Cierre',`.
- `app/Enums/VoucherType.php`: agregar `case Cierre = 'cierre';`, en `label()` `self::Cierre => 'Comprobante de Cierre',` y en `shortLabel()` `self::Cierre => 'CIERRE',`.
- `app/Models/JournalEntry.php` `scopeMovimientos`: agregar `JournalEntryType::Cierre->value` al `whereIn` (junto a Normal y Apertura).
- `app/Services/Accounting/JournalEntryService.php`: en el guard de `createPostedEntry`, cambiar la condición de `allowClosedPeriod` para aceptar apertura **o** cierre:
```php
            if ($allowClosedPeriod && ! in_array($entryTypeCheck, [
                JournalEntryType::Apertura->value,
                JournalEntryType::Cierre->value,
            ], true)) {
                throw new RuntimeException('allowClosedPeriod solo es válido para asientos de apertura o cierre.');
            }
```
  Y en `reverseEntry`, junto al bloqueo de apertura, bloquear cierre:
```php
        if (in_array($entry->entry_type, [JournalEntryType::Apertura->value, JournalEntryType::Cierre->value], true)
            || in_array($entry->entry_type?->value ?? $entry->entry_type, [JournalEntryType::Apertura->value, JournalEntryType::Cierre->value], true)) {
            throw new RuntimeException('El asiento de apertura/cierre no puede revertirse.');
        }
```
  (Nota: `$entry->entry_type` puede venir como enum por el cast; comparar contra `->value`. Simplificá al patrón que ya use el bloqueo de apertura existente — si hoy hace `$entry->entry_type === JournalEntryType::Apertura->value`, entonces `$entry->entry_type` es string ahí; usá `in_array($entry->entry_type, ['apertura','cierre'], true)` reflejando el patrón existente. LEER el bloqueo actual y espejarlo.)

- [ ] **Step 4: Correr — pasa.** Regresión: `php artisan test --filter "JournalEntry|Opening|ClosedPeriodOpening"`
- [ ] **Step 5: Commit** — `git commit -am "feat(contab): enum Cierre + allowClosedPeriod y reverso bloqueado para cierre"`

---

## Task 2: Cuentas de cierre + settings

**Files:** Modify `database/seeders/ChartOfAccountSeeder.php` · Create `database/migrations/2026_09_01_000002_seed_closing_account_codes.php` · Test `tests/Feature/Accounting/ClosingAccountSettingsTest.php`

- [ ] **Step 1: Test que falla**
```php
<?php

namespace Tests\Feature\Accounting;

use App\Models\ChartOfAccount;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClosingAccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_and_accounts(): void
    {
        $this->assertSame('6.8', Setting::get('accounting_iue_expense_code'));
        $this->assertSame('2.1.13', Setting::get('accounting_iue_payable_code'));
        $this->assertSame('3.3', Setting::get('accounting_period_result_code'));

        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);
        $this->assertNotNull(ChartOfAccount::where('code', '2.1.13')->first());
        $this->assertNotNull(ChartOfAccount::where('code', '6.8')->first());
        $this->assertNotNull(ChartOfAccount::where('code', '3.4')->first());
    }
}
```

- [ ] **Step 2: Correr — falla**

- [ ] **Step 3: Cuentas en `ChartOfAccountSeeder`** (idempotente, mismo array de la lección de 6.7). Agregar (con `parent_code` correcto):
```php
            ['code' => '2.1.13', 'name' => 'IUE por Pagar', 'level' => 3, 'parent_code' => '2.1', 'account_type' => 'liability', 'normal_balance' => 'credit', 'allows_posting' => true],
            ['code' => '3.4', 'name' => 'Reserva Legal', 'level' => 2, 'parent_code' => '3', 'account_type' => 'equity', 'normal_balance' => 'credit', 'allows_posting' => true],
            ['code' => '6.8', 'name' => 'Impuesto sobre las Utilidades (IUE)', 'level' => 2, 'parent_code' => '6', 'account_type' => 'expense', 'normal_balance' => 'debit', 'allows_posting' => true],
```
Colocarlas junto a las de su grupo (2.1.13 tras 2.1.12; 3.4 tras 3.3; 6.8 tras 6.7). LEER el seeder para respetar el formato exacto del array.

- [ ] **Step 4: Migración de settings** `database/migrations/2026_09_01_000002_seed_closing_account_codes.php` (insert-if-missing, patrón de `2026_08_27_000002`):
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            'accounting_iue_expense_code'   => '6.8',
            'accounting_iue_payable_code'   => '2.1.13',
            'accounting_period_result_code' => '3.3',
        ];
        foreach ($defaults as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'accounting_iue_expense_code', 'accounting_iue_payable_code', 'accounting_period_result_code',
        ])->delete();
    }
};
```

- [ ] **Step 5: Correr — pasa**
- [ ] **Step 6: Commit** — `git commit -am "feat(contab): cuentas IUE/reserva (6.8, 2.1.13, 3.4) + settings de cierre"`

---

## Task 3: `GestionCierreService::preview`

**Files:** Create `app/Services/Accounting/GestionCierreService.php` · Test `tests/Feature/Accounting/GestionCierrePreviewTest.php`

- [ ] **Step 1: Test que falla**
```php
<?php

namespace Tests\Feature\Accounting;

use App\Models\Setting;
use App\Services\Accounting\GestionCierreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GestionCierrePreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_devuelve_iue_y_reserva(): void
    {
        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);
        $this->seed(\Database\Seeders\SettingSeeder::class);
        $this->seed(\Database\Seeders\AccountingPeriodSeeder::class);
        Setting::set('company_entity_type', 'srl');

        // Sembrar utilidad: postear ventas y capital vía JournalEntryService está cubierto en
        // IncomeStatementClosingTest; aquí basta con que preview() devuelva las claves.
        $p = app(GestionCierreService::class)->preview(2026);

        $this->assertArrayHasKey('iue', $p);
        $this->assertArrayHasKey('reserva_legal', $p);
        $this->assertArrayHasKey('lines', $p);
        $this->assertArrayHasKey('ya_cerrada', $p);
        $this->assertFalse($p['ya_cerrada']);
    }
}
```
(Si `AccountingPeriodSeeder` no crea una gestión 2026, el test debe crear un `AccountingPeriod` Open para 2026 — leer el seeder y ajustar.)

- [ ] **Step 2: Correr — falla**

- [ ] **Step 3: Implementar `preview` (solo)**
```php
<?php

namespace App\Services\Accounting;

use App\Enums\JournalEntryType;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\Setting;
use App\Services\FinancialStatementService;
use RuntimeException;

class GestionCierreService
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
        protected FinancialStatementService $statements,
    ) {}

    /** @return array{utilidad_antes_impuestos:int, iue:int, reserva_legal:int, ya_cerrada:bool, lines:array} */
    public function preview(int $year): array
    {
        $er = $this->statements->build("{$year}-01-01", "{$year}-12-31", withTaxes: true)['estado_resultados'];
        $iue = (int) $er['iue'];
        $reserva = (int) $er['reserva_legal'];

        return [
            'utilidad_antes_impuestos' => (int) $er['utilidad_antes_impuestos'],
            'iue' => $iue,
            'reserva_legal' => $reserva,
            'ya_cerrada' => $this->alreadyClosed($year),
            'lines' => $this->buildLines($iue, $reserva),
        ];
    }

    protected function alreadyClosed(int $year): bool
    {
        $period = $this->gestionPeriod($year);
        if (! $period) {
            return false;
        }
        $existing = $this->journalEntryService->findPostedSourceEntry(AccountingPeriod::class, $period->id);
        return $existing !== null && ($existing->entry_type?->value ?? $existing->entry_type) === JournalEntryType::Cierre->value;
    }

    protected function gestionPeriod(int $year): ?AccountingPeriod
    {
        return AccountingPeriod::whereYear('start_date', $year)->orderBy('start_date')->first();
    }

    /** @return array<int, array{chart_of_account_id:int,debit_amount:int,credit_amount:int,description:string}> */
    protected function buildLines(int $iue, int $reserva): array
    {
        $lines = [];
        if ($iue > 0) {
            $lines[] = $this->line(Setting::get('accounting_iue_expense_code', '6.8'), $iue, 0, 'Provisión IUE 25%');
            $lines[] = $this->line(Setting::get('accounting_iue_payable_code', '2.1.13'), 0, $iue, 'IUE por Pagar');
        }
        if ($reserva > 0) {
            $lines[] = $this->line(Setting::get('accounting_period_result_code', '3.3'), $reserva, 0, 'Apropiación reserva legal');
            $lines[] = $this->line(Setting::get('accounting_legal_reserve_code', '3.4'), 0, $reserva, 'Reserva Legal');
        }
        return $lines;
    }

    protected function line(string $code, int $debit, int $credit, string $desc): array
    {
        return ['chart_of_account_id' => $this->accountId($code), 'debit_amount' => $debit, 'credit_amount' => $credit, 'description' => $desc];
    }

    protected function accountId(string $code): int
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
- [ ] **Step 5: Commit** — `git commit -am "feat(contab): GestionCierreService::preview (IUE + reserva)"`

---

## Task 4: `GestionCierreService::close` (unicidad + posteo)

**Files:** Modify `app/Services/Accounting/GestionCierreService.php` · Test `tests/Feature/Accounting/GestionCierreCloseTest.php`

- [ ] **Step 1: Test que falla** — crear `tests/Feature/Accounting/GestionCierreCloseTest.php`. Sembrar ChartOfAccountSeeder + SettingSeeder + un `AccountingPeriod` Open 2026. `Setting::set('company_entity_type','srl')`. Postear (vía `JournalEntryService`) para generar utilidad: capital (Débito Caja 3.1... usar el patrón de `IncomeStatementClosingTest::postEntry`) y ventas, de modo que `iue>0` y `reserva>0`. Luego:
```php
        $entry = app(GestionCierreService::class)->close(2026, $user->id);
        $this->assertNotNull($entry);
        $this->assertSame('cierre', $entry->entry_type->value);
        $this->assertSame($entry->lines->sum('debit_amount'), $entry->lines->sum('credit_amount'));
        // 2º cierre → excepción
        $this->expectException(\RuntimeException::class);
        app(GestionCierreService::class)->close(2026, $user->id);
```
Copiar el armado de cuentas/periodo/postes de `tests/Feature/Finance/IncomeStatementClosingTest.php` (helpers `acc`/`postEntry`).

- [ ] **Step 2: Correr — falla**

- [ ] **Step 3: Implementar `close`** (agregar a `GestionCierreService`, `use App\Enums\VoucherType; use App\Models\JournalEntry; use Illuminate\Support\Facades\DB;`):
```php
    public function close(int $year, int $userId): ?JournalEntry
    {
        return DB::transaction(function () use ($year, $userId) {
            $period = AccountingPeriod::whereYear('start_date', $year)->orderBy('start_date')->lockForUpdate()->first();
            if (! $period) {
                throw new RuntimeException("No existe período contable para la gestión {$year}.");
            }
            if ($this->journalEntryService->findPostedSourceEntry(AccountingPeriod::class, $period->id)) {
                throw new RuntimeException("La gestión {$year} ya tiene un asiento de cierre/apertura.");
            }

            $er = $this->statements->build("{$year}-01-01", "{$year}-12-31", withTaxes: true)['estado_resultados'];
            $lines = $this->buildLines((int) $er['iue'], (int) $er['reserva_legal']);
            if (empty($lines)) {
                return null; // sin IUE ni reserva → nada que postear
            }

            return $this->journalEntryService->createPostedEntry([
                'entry_date'           => "{$year}-12-31",
                'accounting_period_id' => $period->id,
                'description'          => "Cierre de gestión {$year}: provisión IUE y reserva legal",
                'source_type'          => AccountingPeriod::class,
                'source_id'            => $period->id,
                'voucher_type'         => VoucherType::Cierre->value,
                'entry_type'           => JournalEntryType::Cierre->value,
                'created_by'           => $userId,
                'posted_by'            => $userId,
            ], $lines, allowClosedPeriod: true);
        });
    }
```
NOTA: el guard de unicidad usa `findPostedSourceEntry(AccountingPeriod, period)` — que también encuentra el asiento de **apertura** (mismo ancla). Esto significa: no puede coexistir apertura y cierre en el mismo `source_id` de período. Como la apertura ancla al **primer período de la gestión** y el cierre también, hay colisión de ancla. **Ajuste:** para el cierre, anclar con un `source_id` distinto o diferenciar por `entry_type`. Opción simple y correcta: en el guard del cierre, buscar específicamente un asiento **de tipo cierre** para ese período (no cualquiera). Reemplazar el `if (findPostedSourceEntry(...))` por una query que filtre `entry_type=cierre`:
```php
            $yaCerrada = JournalEntry::query()
                ->where('source_type', AccountingPeriod::class)
                ->where('source_id', $period->id)
                ->where('entry_type', JournalEntryType::Cierre->value)
                ->where('status', \App\Enums\JournalEntryStatus::Posted)
                ->exists();
            if ($yaCerrada) {
                throw new RuntimeException("La gestión {$year} ya está cerrada.");
            }
```
Y en `alreadyClosed()` (Task 3) usar la misma query filtrada por `entry_type=cierre` (ya lo hace conceptualmente; asegurarse de filtrar por tipo, no por `findPostedSourceEntry` genérico). Actualizar `alreadyClosed` para usar esta query.

- [ ] **Step 4: Correr — pasa.** Regresión: `php artisan test --filter "GestionCierre|IncomeStatement|Opening"`
- [ ] **Step 5: Commit** — `git commit -am "feat(contab): GestionCierreService::close con unicidad por gestion"`

---

## Task 5: Wizard Livewire "Cerrar gestión"

**Files:** Create `app/Livewire/Accounting/GestionCierreWizard.php`, `resources/views/livewire/accounting/gestion-cierre-wizard.blade.php`, wrapper view · Modify `routes/web.php`, `resources/views/finance/hubs/contabilidad.blade.php` · Test `tests/Feature/Accounting/GestionCierreWizardTest.php`

READ FIRST: `app/Livewire/Accounting/OpeningBalanceWizard.php` + su vista + su ruta/wrapper (`resources/views/accounting/opening-balance.blade.php`) — espejar el patrón (Route::view a un wrapper con `<x-app-layout>` + `<livewire:.../>`, gate admin).

- [ ] **Step 1: Test que falla**
```php
<?php

namespace Tests\Feature\Accounting;

use App\Livewire\Accounting\GestionCierreWizard;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GestionCierreWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_ve_preview_y_cierra(): void
    {
        $this->seed(\Database\Seeders\ChartOfAccountSeeder::class);
        $this->seed(\Database\Seeders\SettingSeeder::class);
        // crear período Open 2026 + utilidad srl con iue/reserva > 0 (copiar helpers de IncomeStatementClosingTest)
        // ... (setup omitido: seguir el patrón del test de cierre)
        \App\Models\Setting::set('company_entity_type', 'srl');
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(GestionCierreWizard::class)
            ->set('year', 2026)
            ->call('cerrar')
            ->assertHasNoErrors();

        $this->assertTrue(JournalEntry::where('entry_type', 'cierre')->exists());
    }
}
```
NOTA: el setup de utilidad (postes de capital + ventas) debe copiarse del test de cierre para que `iue/reserva > 0` y el cierre postee. Si el setup es complejo, un test más simple: sin utilidad, `cerrar()` no postea pero `assertHasNoErrors()` y muestra "nada que cerrar". Elegir uno y dejarlo verde.

- [ ] **Step 2: Correr — falla**

- [ ] **Step 3: Componente** `app/Livewire/Accounting/GestionCierreWizard.php`:
```php
<?php

namespace App\Livewire\Accounting;

use App\Services\Accounting\GestionCierreService;
use Livewire\Component;

class GestionCierreWizard extends Component
{
    public int $year;

    public function mount(): void
    {
        abort_if(! auth()->user()?->isAdmin(), 403);
        $this->year = (int) now()->year;
    }

    public function getPreviewProperty(GestionCierreService $service): array
    {
        return $service->preview($this->year);
    }

    public function cerrar(GestionCierreService $service): void
    {
        abort_if(! auth()->user()?->isAdmin(), 403);
        try {
            $entry = $service->close($this->year, (int) auth()->id());
            $msg = $entry ? 'Cierre de gestión registrado.' : 'No hay IUE ni reserva que provisionar para esta gestión.';
            session()->flash('ok', $msg);
            $this->dispatch('toast', message: $msg, type: 'success');
        } catch (\Throwable $e) {
            $this->addError('year', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.accounting.gestion-cierre-wizard');
    }
}
```

- [ ] **Step 4: Vista** `resources/views/livewire/accounting/gestion-cierre-wizard.blade.php` — root `<div>`; input `number` para `year` (`wire:model.live="year"`); un bloque que muestre `$this->preview` (utilidad, IUE, reserva, y si `ya_cerrada` un aviso); botón `wire:click="cerrar"` "Cerrar gestión" (deshabilitado si `$this->preview['ya_cerrada']`); `@error('year')` y `@if(session('ok'))`. Espejar clases/estilo de `opening-balance-wizard.blade.php` (dark mode).

- [ ] **Step 5: Wrapper + ruta** — crear `resources/views/accounting/gestion-cierre.blade.php` (`<x-app-layout><livewire:accounting.gestion-cierre-wizard /></x-app-layout>`) y en `routes/web.php` (mismo grupo admin que apertura): `Route::view('finance/cierre-gestion', 'accounting.gestion-cierre')->name('accounting.closing.index');`.

- [ ] **Step 6: Tarjeta en el hub** — en `resources/views/finance/hubs/contabilidad.blade.php`, agregar una tarjeta "Cerrar gestión" → `route('accounting.closing.index')`, gateada por `@if(auth()->user()?->isAdmin())` (como la de apertura). Ícono heroicon acorde.

- [ ] **Step 7: Correr — pasa** (`php artisan test --filter GestionCierreWizardTest`)
- [ ] **Step 8: Commit** — `git commit -am "feat(contab): wizard Cerrar gestion (preview IUE/reserva) + hub"`

---

## Cierre
- [ ] **Suite completa** — `php artisan test`. Sin regresiones (todo código nuevo salvo el flag/scope/reverso ampliados a cierre, y 3 cuentas + settings aditivos).
- [ ] **Nota de deploy** — `php artisan migrate` (aditiva) + `db:seed --class=ChartOfAccountSeeder` (o el mecanismo del proyecto para sembrar las 3 cuentas nuevas). Wizard en `finance/cierre-gestion` (admin), tarjeta en hub Contabilidad. El cierre solo provisiona IUE + reserva; el tipo societario se configura en Ajustes (C1).
- [ ] **Follow-ups:** cierre de resultados completo (saldar 4/5/6 → 3.3 → 3.2); reverso controlado del cierre; captura de factura en compras (B1).

Al terminar → **superpowers:finishing-a-development-branch**.

---

## Self-review (checklist del autor)
- **Cobertura de spec:** R1 (T2 cuentas), R2 (T2 settings), R3 (T1 enum+scope), R4 (T1 allowClosedPeriod), R5 (T1 reverso), R6 (T3 preview), R7 (T4 close+unicidad), R8 (T5 wizard). ✔
- **Placeholders:** código completo T1-T4. T5 (vista/wizard setup de test) referencia patrones existentes (opening wizard, IncomeStatementClosingTest helpers) — el implementador los lee. ✔
- **Consistencia:** `GestionCierreService::preview/close/buildLines/accountId` idénticos entre T3/T4. `entry_type=cierre` en enum/scope/allowClosedPeriod/reverso/close. Cuentas 6.8/2.1.13/3.4/3.3 por Setting, mismas en seeder/service/tests. Ancla de unicidad = `source_type=AccountingPeriod`+`source_id=period` **filtrando por entry_type=cierre** (evita colisión con la apertura). ✔
- **Riesgo anotado:** la colisión de ancla apertura/cierre se resuelve filtrando por `entry_type` en el guard (T4 nota). `entry_type` castea a enum → comparar con `->value`. ✔
