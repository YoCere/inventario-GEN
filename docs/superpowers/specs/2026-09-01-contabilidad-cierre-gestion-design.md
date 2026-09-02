# Cierre de Gestión: Provisión IUE + Reserva Legal (C2) — Diseño

**Fecha:** 2026-09-01
**Estado:** Aprobado (diseño) — pendiente revisión de spec
**Parte de:** Mejoras contables del contador. Tema **C, pieza 2** (asientos de cierre). C1 (cálculo +
presentación del Estado de Resultados) ya en main. Depende de A (apertura, capital) y C1.

## Objetivo

Una acción **"Cerrar gestión"** (admin) que muestra un **preview** de los asientos a postear (calculados
por C1) y, al confirmar, **postea la provisión de IUE y la reserva legal** de la gestión. Idempotente (una
sola vez por gestión). **NO** hace el cierre de resultados completo (los reportes ya calculan el P&L por
rango de fecha).

## Decisiones (fijadas con el usuario)

| Decisión | Elegido |
|---|---|
| Disparo | **Acción manual con preview** (el contador revisa y confirma). |
| Alcance | **Solo provisiones**: IUE + reserva legal. Sin cierre de resultados (saldar 4/5/6 → 3.3 → 3.2). |

## Asientos objetivo

- **Provisión IUE** (si `iue > 0`): Débito **Gasto IUE (6.8)** / Crédito **IUE por Pagar (2.1.13)**, monto = `iue`.
- **Reserva legal** (si `reserva > 0`): Débito **Resultado del Ejercicio (3.3)** / Crédito **Reserva Legal (3.4)**, monto = `reserva`.

Ambos montos vienen de `FinancialStatementService` (C1). Si `iue = 0` (pérdida) → no se postea IUE. Si
`reserva = 0` (unipersonal/persona natural, o sin margen) → no se postea reserva. Si ninguno aplica, el
cierre no postea nada y lo informa.

## Estado actual (con file:line)

- `JournalEntryService::createPostedEntry(payload, lines, bool $allowClosedPeriod=false)` valida cuadre y
  período; `allowClosedPeriod` hoy solo permite `entry_type=apertura` (`app/Services/Accounting/JournalEntryService.php`).
  `findPostedSourceEntry(sourceType, sourceId)` para unicidad. `reverseEntry` bloquea `apertura`.
- `FinancialStatementService::build(from, to, withTaxes)` devuelve `estado_resultados` con `iue`,
  `reserva_legal`, `utilidad_antes_impuestos`, etc. (C1, en main).
- Cuentas: `3.3 Resultado del Ejercicio` (equity), `3.1 Capital`, `3.2 Resultados Acumulados` **existen**.
  **Faltan** `2.1.13 IUE por Pagar` (liability), `6.8 Gasto IUE` (expense), `3.4 Reserva Legal` (equity).
- Settings de C1: `company_entity_type`, `tax_iue_rate`, `legal_reserve_rate`, `legal_reserve_cap_pct`,
  `accounting_legal_reserve_code`=3.4 (ya sembrado). **Faltan** `accounting_iue_expense_code`,
  `accounting_iue_payable_code`, `accounting_period_result_code`.
- `AccountingPeriodAutoCloser` solo rota períodos (mensual); NO es cierre contable. C2 es independiente.

## Requisitos

| # | Requisito |
|---|-----------|
| R1 | **Cuentas** (en `ChartOfAccountSeeder`, idempotente `updateOrCreate`, NUNCA en migración — evita huérfanos): `2.1.13 IUE por Pagar` (liability/credit, parent 2.1), `6.8 Impuesto sobre Utilidades (IUE)` (expense/debit, parent 6), `3.4 Reserva Legal` (equity/credit, parent 3). |
| R2 | **Settings** (migración aditiva insert-if-missing): `accounting_iue_expense_code`=`6.8`, `accounting_iue_payable_code`=`2.1.13`, `accounting_period_result_code`=`3.3`. (`accounting_legal_reserve_code`=3.4 ya existe de C1.) |
| R3 | Enum `JournalEntryType::Cierre='cierre'` + `VoucherType::Cierre='cierre'` (con label). `scopeMovimientos` incluye `cierre` (como apertura). |
| R4 | Extender `allowClosedPeriod` en `createPostedEntry` para aceptar también `entry_type=cierre` (postear al fin de gestión aunque el período esté cerrado). El guard sigue rechazando `normal`. |
| R5 | `reverseEntry` bloquea también `entry_type=cierre` (además de apertura). |
| R6 | `App\Services\Accounting\GestionCierreService::preview(int $year): array` — usa `FinancialStatementService::build("$year-01-01","$year-12-31", withTaxes:true)`; devuelve `{utilidad_antes_impuestos, iue, reserva_legal, ya_cerrada:bool, lines:[...]}` (las líneas que se postearían, resueltas contra las cuentas por Setting). No postea nada. |
| R7 | `GestionCierreService::close(int $year, int $userId): App\Models\JournalEntry\|null` — en `DB::transaction`: resuelve el período de la gestión (primer período del año, `lockForUpdate`); **guard de unicidad** (si ya existe un asiento `source_type=AccountingPeriod`/`source_id=<period>` con `entry_type=cierre` → excepción "gestión ya cerrada"); arma las líneas (IUE + reserva según apliquen); si no hay ninguna línea → no postea, devuelve null con motivo; postea **un** asiento `entry_type=cierre`, `voucher_type=cierre`, fechado `"$year-12-31"`, vía `createPostedEntry(..., allowClosedPeriod:true)`. |
| R8 | **UI Livewire `App\Livewire\Accounting\GestionCierreWizard`** (`abort_if(!isAdmin(),403)`): selector de año (default gestión actual), muestra el **preview** (utilidad, IUE, reserva, y si ya está cerrada), y un botón "Cerrar gestión" que llama `close()`. Ruta admin `finance/cierre-gestion` (`accounting.closing.index`), tarjeta en el hub Contabilidad. Errores del servicio → mensaje en pantalla, no 500. |

## Arquitectura

Reusa `JournalEntryService` (doble partida, centavos, read-model) y `FinancialStatementService` (C1, la
fuente de los montos). La lógica nueva vive en `GestionCierreService` (preview + close + unicidad) y el
wizard. Cuentas por Setting (sin hardcode). El cierre es **un solo asiento** con las líneas que apliquen
(IUE y/o reserva), anclado a la gestión para la unicidad. Cada pieza testeable por separado: el servicio
sin UI, el wizard sobre el servicio.

## Nota contable

La reserva debita **Resultado del Ejercicio (3.3)**; como no se hace el cierre de resultados completo, ese
débito refleja la apropiación de la reserva. La provisión de IUE reconoce el gasto + el pasivo tributario.
Los códigos y la validez fiscal los confirma el contador del negocio.

## Manejo de errores / invariantes

- 2º cierre de la misma gestión → excepción (guard `lockForUpdate` + `findPostedSourceEntry`).
- `iue=0` → sin línea IUE; `reserva=0` → sin línea reserva; ambos 0 → no postea, informa.
- Reverso del asiento de cierre → bloqueado (R5).
- Sin período para la gestión → excepción con mensaje claro.
- Capital ausente (sin apertura) → la reserva de C1 ya degrada a 0; el cierre no rompe.
- Cada asiento cuadra (`validateLines`).

## Testing (PHPUnit + RefreshDatabase, centavos int)

- `preview(año)` con utilidad>0 y srl → iue y reserva > 0, `lines` con las 2 (IUE 2 líneas + reserva 2 líneas… en un solo asiento de 4 líneas que cuadra).
- `close(año)` postea un asiento `entry_type=cierre` que cuadra; IUE en 6.8/2.1.13, reserva en 3.3/3.4.
- Pérdida → sin línea IUE (y si tampoco reserva → no postea, devuelve null).
- Unipersonal → sin reserva.
- **Unicidad:** 2º `close(mismo año)` lanza excepción.
- Reverso del cierre → excepción.
- Wizard: admin ve el preview y postea; el asiento queda en la DB; no-admin → 403.

## Fuera de alcance

- Cierre de resultados completo (saldar income/cost/expense → 3.3 → 3.2) → follow-up.
- Rotación mensual de períodos / cierre automático → sin cambios.
- Presentación jerárquica del plan (B2 ya cerró su alcance).
