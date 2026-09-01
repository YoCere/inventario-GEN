# Estado de Resultados con CMV, IUE y Reserva Legal (C1) — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Presentar el Estado de Resultados con estructura CMV + utilidad bruta + IUE 25% + reserva legal 5% condicional + utilidad de la gestión — solo cálculo/presentación, sin postear asientos.

**Architecture:** Extender `FinancialStatementService` (que ya calcula los 3 sets de saldos: período, acumulado, apertura) para armar el bloque CMV y la cadena de utilidad. Un setting `company_entity_type` condiciona la reserva legal. Todo es cálculo puro sobre saldos existentes; sin servicios ni asientos nuevos.

**Tech Stack:** Laravel 11, PHPUnit class-style + RefreshDatabase, Blade + Tailwind, MySQL. Sin dependencias nuevas.

**Spec:** `docs/superpowers/specs/2026-09-01-contabilidad-estado-resultados-design.md`

---

## Convenciones
- **NUNCA `migrate:fresh`**. Migraciones aditivas, seeders idempotentes.
- Dinero en **centavos-int**. Tasas/cuentas por `Setting::get(...)`.
- Tests: `extends Tests\TestCase`, `use RefreshDatabase`. Correr `php artisan test --filter <Clase>`.

## Anclajes verificados (`app/Services/FinancialStatementService.php`)
- `build(from,to,withTaxes)` (`:22-56`) calcula `$periodBalances` (movimientos del período), `$cumulativeBalances` (acumulado a `to`), `$openingBalances` (acumulado al día anterior a `from`), y llama `buildIncomeStatement($periodBalances, $withTaxes)` (`:33`).
- `calculateAccountBalances(from,to)` (`:61-79`) devuelve una Collection de objetos con: `->code`, `->name`, `->account_type`, `->normal_balance`, `->debit_total` (int), `->credit_total` (int), `->balance` (int, firmado por normal_balance: activo positivo=deudor, pasivo/patrimonio positivo=acreedor).
- `buildIncomeStatement(Collection $periodBalances, bool $withTaxes)` (`:105-129`) suma `income/cost/expense` por `account_type`, `net_result = income − cost − expense`, y `taxes` (desde B1 lee saldos reales) + `net_result_after_tax`.
- Constructor inyecta `LedgerBalanceService $ledger` + `InvestmentMetrics $metrics` (`:15`). `use App\Models\Setting;` ya importado.
- Cuentas: Ventas `4.1`, Costo `5.1`, Inventario `1.1.04`, Capital `3.1`.

## File Structure
- Create `database/migrations/2026_09_01_000001_seed_closing_settings.php` — settings de cierre.
- Modify `app/Services/FinancialStatementService.php` — CMV + cadena de utilidad en el EERR.
- Modify `resources/views/finance-statements/index.blade.php` — mostrar la estructura nueva.
- Tests en `tests/Feature/Finance/`.

---

## Task 1: Settings de cierre (tipo societario + tasas)

**Files:** Create `database/migrations/2026_09_01_000001_seed_closing_settings.php` · Test `tests/Feature/Finance/ClosingSettingsTest.php`

- [ ] **Step 1: Test que falla**
```php
<?php

namespace Tests\Feature\Finance;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClosingSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_closing_settings_seeded(): void
    {
        $this->assertSame('unipersonal', Setting::get('company_entity_type'));
        $this->assertSame('25', Setting::get('tax_iue_rate'));
        $this->assertSame('5', Setting::get('legal_reserve_rate'));
        $this->assertSame('50', Setting::get('legal_reserve_cap_pct'));
        $this->assertSame('3.4', Setting::get('accounting_legal_reserve_code'));
    }
}
```
- [ ] **Step 2: Correr — falla** (`php artisan test --filter ClosingSettingsTest`)
- [ ] **Step 3: Migración** (patrón insert-if-missing de `2026_05_21_120000`)
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            'company_entity_type'           => 'unipersonal', // unipersonal|persona_natural|srl|sa
            'tax_iue_rate'                  => '25',
            'legal_reserve_rate'            => '5',
            'legal_reserve_cap_pct'         => '50',
            'accounting_legal_reserve_code' => '3.4',
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
            'company_entity_type', 'tax_iue_rate', 'legal_reserve_rate',
            'legal_reserve_cap_pct', 'accounting_legal_reserve_code',
        ])->delete();
    }
};
```
- [ ] **Step 4: Correr — pasa**
- [ ] **Step 5: Commit** — `git commit -am "feat(contab): settings de cierre (tipo societario, IUE, reserva)"`

---

## Task 2: CMV + cadena de utilidad (IUE + reserva) en el EERR

**Files:** Modify `app/Services/FinancialStatementService.php` · Test `tests/Feature/Finance/IncomeStatementClosingTest.php`

- [ ] **Step 1: Test que falla**
```php
<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountingPeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use App\Services\FinancialStatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomeStatementClosingTest extends TestCase
{
    use RefreshDatabase;

    private function acc(string $code, string $name, string $type, string $nb): void
    {
        ChartOfAccount::updateOrCreate(['code' => $code], [
            'name' => $name, 'level' => strlen($code) > 3 ? 3 : 2, 'account_type' => $type,
            'normal_balance' => $nb, 'allows_posting' => true, 'is_active' => true,
        ]);
    }

    private function seedAll(): void
    {
        $this->acc('1.1.01', 'Caja', 'asset', 'debit');
        $this->acc('1.1.04', 'Inventario', 'asset', 'debit');
        $this->acc('3.1', 'Capital', 'equity', 'credit');
        $this->acc('4.1', 'Ventas', 'income', 'credit');
        $this->acc('5.1', 'Costo de Ventas', 'cost', 'debit');
        $this->acc('6.1', 'Gastos', 'expense', 'debit');
        AccountingPeriod::create(['name' => 'Gestion', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => AccountingPeriodStatus::Open]);
    }

    /** Postea un asiento simple (2 líneas) para dar saldo a las cuentas. */
    private function post(string $debitCode, string $creditCode, int $cents): void
    {
        $user = User::factory()->create();
        $period = AccountingPeriod::first();
        app(JournalEntryService::class)->createPostedEntry([
            'entry_date' => '2026-06-01', 'accounting_period_id' => $period->id, 'created_by' => $user->id,
        ], [
            ['chart_of_account_id' => ChartOfAccount::where('code', $debitCode)->value('id'), 'debit_amount' => $cents],
            ['chart_of_account_id' => ChartOfAccount::where('code', $creditCode)->value('id'), 'credit_amount' => $cents],
        ]);
    }

    public function test_utilidad_positiva_calcula_iue_y_estructura(): void
    {
        $this->seedAll();
        // Ventas 100.000 (Caja debe / Ventas haber), Costo 60.000 (Costo debe / Inventario haber),
        // Gastos 10.000 (Gastos debe / Caja haber). Utilidad antes = 100.000 - 60.000 - 10.000 = 30.000.
        $this->post('1.1.01', '4.1', 10000000);
        $this->post('5.1', '1.1.04', 6000000);
        $this->post('6.1', '1.1.01', 1000000);

        $r = app(FinancialStatementService::class)->build('2026-01-01', '2026-12-31', withTaxes: true);
        $er = $r['estado_resultados'];

        $this->assertSame(10000000, $er['ventas']);
        $this->assertSame(6000000, $er['cmv']['cmv_total']);
        $this->assertSame(4000000, $er['utilidad_bruta']);          // 100.000 - 60.000
        $this->assertSame(3000000, $er['utilidad_antes_impuestos']); // = net_result
        $this->assertSame(750000, $er['iue']);                      // 25% de 30.000
        $this->assertSame(2250000, $er['utilidad_despues_impuestos']);
        $this->assertSame(0, $er['reserva_legal']);                 // unipersonal por default
        $this->assertSame(2250000, $er['utilidad_gestion']);
    }

    public function test_perdida_no_calcula_iue(): void
    {
        $this->seedAll();
        // Ventas 10.000, Costo 60.000 -> utilidad antes negativa.
        $this->post('1.1.01', '4.1', 1000000);
        $this->post('5.1', '1.1.04', 6000000);

        $er = app(FinancialStatementService::class)->build('2026-01-01', '2026-12-31', withTaxes: true)['estado_resultados'];

        $this->assertTrue($er['utilidad_antes_impuestos'] < 0);
        $this->assertSame(0, $er['iue']);
        $this->assertSame(0, $er['reserva_legal']);
    }

    public function test_srl_calcula_reserva_legal_con_tope(): void
    {
        $this->seedAll();
        \App\Models\Setting::set('company_entity_type', 'srl');
        // Capital 1.000.000 (Caja debe / Capital haber) -> tope reserva = 50% = 500.000.
        $this->post('1.1.01', '3.1', 100000000);
        // Utilidad: Ventas 100.000, sin costo/gasto -> utilidad antes = 100.000, IUE 25.000, despues 75.000.
        $this->post('1.1.01', '4.1', 10000000);

        $er = app(FinancialStatementService::class)->build('2026-01-01', '2026-12-31', withTaxes: true)['estado_resultados'];

        // reserva = 5% de 75.000 = 3.750 (menor al tope 500.000)
        $this->assertSame(375000, $er['reserva_legal']);
        $this->assertSame($er['utilidad_despues_impuestos'] - 375000, $er['utilidad_gestion']);
    }
}
```
NOTA: verificar que un `AccountingPeriod` Open cubra `2026-06-01` (fecha de los asientos) — el seed crea la gestión completa Open. Si `ChartOfAccount`/`AccountingPeriod` requieren columnas extra, agregarlas.

- [ ] **Step 2: Correr — falla**

- [ ] **Step 3: Implementar** — en `app/Services/FinancialStatementService.php`:

(a) Cambiar la firma y el llamador. En `build()` (línea ~33), cambiar:
```php
        $estadoResultados = $this->buildIncomeStatement($periodBalances, $openingBalances, $cumulativeBalances, $withTaxes);
```
(b) Cambiar la firma de `buildIncomeStatement` y agregar el bloque CMV + cadena de utilidad. Reemplazar el cuerpo actual (`:105-129`) por:
```php
    protected function buildIncomeStatement(
        Collection $periodBalances,
        Collection $openingBalances,
        Collection $cumulativeBalances,
        bool $withTaxes
    ): array {
        $income = $periodBalances->where('account_type', 'income')->values();
        $costs = $periodBalances->where('account_type', 'cost')->values();
        $expenses = $periodBalances->where('account_type', 'expense')->values();

        $incomeTotal = (int) $income->sum('balance');
        $costTotal = (int) $costs->sum('balance');
        $expenseTotal = (int) $expenses->sum('balance');
        $netResult = $incomeTotal - $costTotal - $expenseTotal;
        $taxes = $this->buildTaxBreakdown($periodBalances, $withTaxes);

        // --- Estructura del contador ---
        $inventoryCode = Setting::get('accounting_inventory_code', '1.1.04');
        $capitalCode   = Setting::get('accounting_opening_capital_code', '3.1');
        $reserveCode   = Setting::get('accounting_legal_reserve_code', '3.4');

        $balanceOf = fn (Collection $c, string $code) => (int) (optional($c->firstWhere('code', $code))->balance ?? 0);
        $debitOf   = fn (Collection $c, string $code) => (int) (optional($c->firstWhere('code', $code))->debit_total ?? 0);

        // Ventas vs otros ingresos (por prefijo de código).
        $ventas = (int) $income->filter(fn ($a) => str_starts_with((string) $a->code, '4.1'))->sum('balance');
        $otrosIngresos = $incomeTotal - $ventas;

        // CMV: desglose informativo (reconciliación) + total real (= saldo de 5.1 = costTotal).
        $cmv = [
            'inventario_inicial' => $balanceOf($openingBalances, $inventoryCode),
            'compras_periodo'    => $debitOf($periodBalances, $inventoryCode),
            'inventario_final'   => $balanceOf($cumulativeBalances, $inventoryCode),
            'devoluciones'       => 0, // sin cuenta de devoluciones de compra
            'cmv_total'          => $costTotal,
        ];

        $utilidadBruta = $ventas - $cmv['cmv_total'];
        $utilidadAntesImpuestos = $utilidadBruta - $expenseTotal + $otrosIngresos; // = netResult

        $iueRate = (float) Setting::get('tax_iue_rate', '25');
        $iue = $utilidadAntesImpuestos > 0 ? (int) round($utilidadAntesImpuestos * $iueRate / 100) : 0;
        $utilidadDespuesImpuestos = $utilidadAntesImpuestos - $iue;

        $reserva = $this->computeLegalReserve($utilidadDespuesImpuestos, $cumulativeBalances, $capitalCode, $reserveCode, $balanceOf);
        $utilidadGestion = $utilidadDespuesImpuestos - $reserva;

        return [
            'income_accounts' => $income,
            'cost_accounts' => $costs,
            'expense_accounts' => $expenses,
            'income_total' => $incomeTotal,
            'cost_total' => $costTotal,
            'expense_total' => $expenseTotal,
            'net_result' => $netResult,
            'with_taxes' => $withTaxes,
            'taxes' => $taxes,
            'net_result_after_tax' => $netResult - $taxes['total_tax'],
            // Estructura del contador:
            'ventas' => $ventas,
            'otros_ingresos' => $otrosIngresos,
            'cmv' => $cmv,
            'utilidad_bruta' => $utilidadBruta,
            'gastos_operacion' => $expenseTotal,
            'utilidad_antes_impuestos' => $utilidadAntesImpuestos,
            'iue' => $iue,
            'utilidad_despues_impuestos' => $utilidadDespuesImpuestos,
            'reserva_legal' => $reserva,
            'utilidad_gestion' => $utilidadGestion,
        ];
    }

    /** Reserva legal 5% solo para S.A./S.R.L., con tope 50% del capital. */
    protected function computeLegalReserve(int $utilidadDespues, Collection $cumulativeBalances, string $capitalCode, string $reserveCode, callable $balanceOf): int
    {
        $type = Setting::get('company_entity_type', 'unipersonal');
        if (! in_array($type, ['srl', 'sa'], true) || $utilidadDespues <= 0) {
            return 0;
        }
        $rate = (float) Setting::get('legal_reserve_rate', '5');
        $capPct = (float) Setting::get('legal_reserve_cap_pct', '50');

        $capital = $balanceOf($cumulativeBalances, $capitalCode);
        $tope = (int) round($capital * $capPct / 100);
        $reservaActual = $balanceOf($cumulativeBalances, $reserveCode);
        $margen = max($tope - $reservaActual, 0);

        $reserva = (int) round($utilidadDespues * $rate / 100);
        return min($reserva, $margen);
    }
```
`Collection` (`Illuminate\Support\Collection`) y `Setting` ya están importados en el archivo. Verificar.

- [ ] **Step 4: Correr — pasa.** Regresión: `php artisan test --filter "FinancialStatement|IncomeStatement|TaxBreakdown"`
- [ ] **Step 5: Commit** — `git commit -am "feat(contab): Estado de Resultados con CMV, IUE y reserva legal (calculo)"`

---

## Task 3: Vista del Estado de Resultados

**Files:** Modify `resources/views/finance-statements/index.blade.php` · Test `tests/Feature/Finance/IncomeStatementViewTest.php`

READ FIRST: `resources/views/finance-statements/index.blade.php` (cómo se renderiza hoy el `estado_resultados` — clases Tailwind, dark mode, estructura de filas) y `app/Http/Controllers/FinancialStatementController.php` (cómo se pasa `$data` a la vista y la ruta `finance.statements.index`).

- [ ] **Step 1: Test que falla** — `tests/Feature/Finance/IncomeStatementViewTest.php`: como admin, hacer un GET a la ruta del reporte (`route('finance.statements.index')` con `withTaxes`/rango que incluya datos, o el método que use el controller) y asertar que la respuesta contiene los textos nuevos de la estructura: `assertSee('Utilidad Bruta')`, `assertSee('IUE')`, `assertSee('Utilidad de la Gestión')`. READ el controller para saber los parámetros de request correctos y crear el usuario/rol admin como en `tests/Feature/Accounting/TrialBalancePageTest.php`.

- [ ] **Step 2: Correr — falla**

- [ ] **Step 3: Implementar** — en la vista, dentro de la sección del Estado de Resultados, mostrar la estructura del contador usando las claves nuevas de `$data['estado_resultados']` (o como se llame la variable en la vista): `ventas`, bloque `cmv` (inventario_inicial/compras_periodo/inventario_final/cmv_total), `utilidad_bruta`, `gastos_operacion`, `otros_ingresos`, `utilidad_antes_impuestos`, `iue`, `utilidad_despues_impuestos`, `reserva_legal`, `utilidad_gestion`. Formatear centavos→bolivianos con el helper que ya use la vista (buscar cómo formatea los demás montos, p.ej. `number_format($x/100, 2)`). La línea **Reserva Legal solo mostrarla** si `$er['reserva_legal'] > 0` o si el tipo societario aplica (opcional: siempre mostrarla, en 0). Respetar el markup/clases existentes (dark mode incluido). No romper las secciones existentes (Balance General, etc.).

- [ ] **Step 4: Correr — pasa**
- [ ] **Step 5: Commit** — `git commit -am "feat(contab): vista del Estado de Resultados con estructura del contador"`

---

## Cierre
- [ ] **Suite completa** — `php artisan test`. Sin regresiones en reportes financieros ni en B1 (el `taxes`/`net_result_after_tax` quedan igual; solo se agregan claves nuevas al array).
- [ ] **Nota de deploy** — `php artisan migrate` (aditiva). El tipo societario se configura en Ajustes (setting `company_entity_type`). El EERR es solo presentación — no genera asientos.
- [ ] **Follow-ups:** tema **C2** (asientos de cierre: provisión IUE, reserva legal, cierre de resultados, con orden período/gestión); crear la cuenta contable de Reserva Legal (en C2); exponer `company_entity_type` y las tasas en la UI de Ajustes si no aparecen automáticamente; tema **B2** (plan multinivel).

Al terminar → **superpowers:finishing-a-development-branch**.

---

## Self-review (checklist del autor)
- **Cobertura de spec:** R1 (T1), R2 (T2 bloque cmv), R3 (T2 cadena), R4 (T2 iue), R5 (T2 computeLegalReserve), R6 (T2 — utilidad usa netResult, taxes queda informativo), R7 (T3 vista). ✔
- **Placeholders:** código completo en T1-T2. T3 (vista) se especifica por claves + patrón a espejar (el implementador lee la vista actual) — es UI. ✔
- **Consistencia:** claves del array (`ventas`, `cmv.cmv_total`, `utilidad_bruta`, `utilidad_antes_impuestos`, `iue`, `utilidad_despues_impuestos`, `reserva_legal`, `utilidad_gestion`) idénticas en T2 (servicio), sus tests, y T3 (vista). `buildIncomeStatement(4 args)` actualizado en su único llamador (`build`). `balanceOf`/`debitOf` sobre las colecciones que `build()` ya provee. ✔
- **Álgebra:** `utilidad_antes_impuestos = utilidad_bruta − gastos + otros = (ventas−cmv) − expense + otros = income − cost − expense = net_result` (cmv_total = cost_total, ventas+otros = income_total). Consistente con el cálculo base. ✔
- **A verificar:** columnas NOT NULL de ChartOfAccount/AccountingPeriod en tests; que la vista actual use `estado_resultados` con el nombre esperado; que exista una cuenta income `4.1` para separar ventas de otros (si no hay otros ingresos, `otros_ingresos=0`). ✔
