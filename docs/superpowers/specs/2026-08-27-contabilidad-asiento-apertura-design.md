# Asiento/Comprobante de Apertura Contable — Diseño

**Fecha:** 2026-08-27
**Estado:** Aprobado (diseño) — pendiente revisión de spec
**Parte de:** Mejoras contables pedidas por el contador (observaciones en `docs/Observaciones de contador/`).
Es el tema **A** de tres (A=apertura, B=multinivel+IVA/IT, C=cierre IUE/reserva). Prioridad del cliente.

## Objetivo

Registrar el **asiento/comprobante de apertura**: el punto de partida contable que refleja con qué inicia
la empresa (Caja, Banco, Inventario, Cuentas por Cobrar, PPE, deudas/CxP, préstamos, y Capital). Es "lo
más importante" según el contador. Debe ser único por gestión, cargable por un wizard con valores
autopropuestos, y **posteable de forma retroactiva** al negocio que ya está operando.

Ejemplo del contador (debe cuadrar Activo = Pasivo + Patrimonio):
- ACTIVO 320.500: Caja 180.000, Documentos por cobrar 15.000, Inventarios 90.500, Muebles/PPE 35.000
- PASIVO 10.000: Cuentas por pagar 10.000 · PATRIMONIO 310.500: Capital Social 310.500

## Decisiones (fijadas con el usuario)

| Decisión | Elegido |
|---|---|
| Backfill retroactivo | **Path admin controlado**: postear `apertura` a un período cerrado, una vez, guarded admin, fechado al inicio de gestión. No reabrir/recerrar. |
| Alcance de este spec | **Mecanismo + wizard + backfill.** Jubilar `opening_balance_amount` = follow-up. |
| Capital Social | **Plug auto-calculado, editable** (Activos − Pasivos), el contador lo confirma. |

## Estado actual (del análisis, con file:line)

- **Motor de asientos listo y reusable:** `JournalEntryService::createPostedEntry()` acepta `entry_type`
  arbitrario, valida doble partida/cuadre en centavos-int (`app/Services/Accounting/JournalEntryService.php:52-56,64-67,181-209`),
  numera vouchers por `(periodo,tipo)` con índice único (`:161-176`).
- **Read-model automático:** el listener `UpdateLedgerSnapshot` alimenta `ledger_account_daily` para
  cualquier `entry_type` (`app/Listeners/UpdateLedgerSnapshot.php:26-60`); `LedgerBalanceService::balancesAt()`
  incluye todos los tipos (`app/Services/Accounting/LedgerBalanceService.php:18-20`). Una apertura fluye
  a saldos/balance sin tocar proyector.
- **Falta la capa de apertura:** `JournalEntryType` solo tiene `Normal`/`Ajuste` (`app/Enums/JournalEntryType.php:7-8`);
  `VoucherType` solo `Ingreso/Egreso/Traspaso` (`app/Enums/VoucherType.php:7-9`). No hay servicio/wizard/unicidad.
- **`opening_balance_amount`** es un placeholder escalar en bolivianos (no centavos, sin contrapartida)
  usado en ROI/VAN (`database/seeders/SettingSeeder.php:19-20`, `FinancialStatementService.php:256`,
  `BudgetProjectionService.php:111`). **Fuera de alcance jubilarlo aquí.**
- **`is_opening` huérfanos:** `FixedAsset::registerOpening()` y `Loan::registerOpening()` crean el activo/
  préstamo **sin postear asiento** (`app/Services/Accounting/FixedAssetService.php:49-62`,
  `LoanService.php:53-77`). El asiento de apertura es su contrapartida faltante (DEBE PPE / HABER Préstamo,
  contra Capital).
- **Cuentas ya sembradas** (`database/seeders/ChartOfAccountSeeder.php`): Caja `1.1.01`, Bancos `1.1.02`,
  CxC `1.1.03`, Inventario `1.1.04`, PPE `1.2.01`, Dep.Acum `1.2.02`, CxP `2.1.01`, Capital Social `3.1`.
- **Mapeo centralizado** vía `Setting::get('accounting_*_code', …)` (patrón `SaleAccountingService.php:40-49`).

## Requisitos

| # | Requisito |
|---|-----------|
| R1 | `JournalEntryType::Apertura = 'apertura'` y `VoucherType::Apertura`. Ajustar `scopeMovimientos`/`scopeAjustes` (`app/Models/JournalEntry.php:70-78`) para que la apertura aparezca en el listado de asientos. |
| R2 | DTO `App\DTOs\OpeningBalanceData` con rubros en **centavos-int**: `cash, bank, inventory, receivable, ppe, accumulated_depreciation, payable, loans, capital`. |
| R3 | `App\Services\Accounting\OpeningBalanceService::propose(string $date): OpeningBalanceData` — autopropone: `inventory` = Σ `product_stocks.quantity × costo`; `ppe` = Σ `FixedAsset::where('is_opening')` costo; `accumulated_depreciation` = Σ dep. acumulada; `loans` = Σ `Loan::where('is_opening')` saldo pendiente; `cash/bank/receivable/payable` = 0; `capital` = plug (Σ activos − Σ pasivos). |
| R4 | `OpeningBalanceService::post(OpeningBalanceData $data, string $date, int $userId): JournalEntry` — valida unicidad por gestión (con `lockForUpdate`), arma líneas doble-partida (activos al DEBE; pasivos+capital al HABER; Dep.Acum es contra-activo → HABER), delega en el path de posteo de apertura. Todo en `DB::transaction`. |
| R5 | Mapeo de cuentas por Setting, claves nuevas idempotentes: `accounting_opening_cash_code`=1.1.01, `_bank_code`=1.1.02, `_inventory_code`=1.1.04, `_receivable_code`=1.1.03, `_ppe_code`=1.2.01, `_depreciation_code`=1.2.02, `_payable_code`=2.1.01, `_loan_code`=<préstamos por pagar>, `_capital_code`=3.1. Migración aditiva estilo `2026_05_21_120000`. |
| R6 | **Unicidad por gestión:** una apertura por año fiscal, anclada al primer `AccountingPeriod` de la gestión vía `source_type=AccountingPeriod` + `source_id=<period_id>` (reusa el morph existente y `findPostedSourceEntry()` `JournalEntryService.php:104-112`). Mecanismo primario: guard en el servicio con `lockForUpdate` sobre la verificación de existencia antes de postear. Blindaje DB opcional: índice único sobre `(source_type, source_id)` limitado a apertura si el motor lo permite; si no, el guard de servicio es suficiente. |
| R7 | **Path de posteo controlado:** permitir postear a un período **cerrado** exclusivamente cuando `entry_type=apertura`, una sola vez, guarded (solo admin). El check de período `Open` (`JournalEntryService.php:52-56`) se conserva para todo lo demás. Idempotente: si ya existe apertura para la gestión, no duplica. |
| R8 | **Wizard Livewire `OpeningBalanceWizard`** (patrón `ManualJournalEntryForm.php:101-115`): campos por rubro, autopropuesta precargada, **preview de cuadre en vivo**, Capital plug calculado y editable, guarda multiplicando ×100. `abort_if(!admin, 403)`. |
| R9 | **Backfill/reconciliación:** al proponer, incluir las líneas derivadas de `is_opening` assets/loans (su contrapartida contra Capital cierra los huérfanos). **Advertir** en el wizard si se detectan compras/ventas retroactivas que ya movieron Inventario, para no duplicar el stock inicial. |
| R10 | **Reverso de apertura bloqueado:** `reverseEntry` (`JournalEntryService.php:114-159`) rechaza `entry_type=apertura` (o exige un flujo admin especial). |

## Arquitectura

**La apertura es un `JournalEntry` como cualquier otro** — reusa motor, validación de cuadre, centavos y
read-model. La única lógica nueva vive en `OpeningBalanceService` (arma líneas + unicidad + path
controlado) y el wizard (captura + autopropuesta + preview). Un DTO aísla los rubros. El mapeo de cuentas
es 100% Setting (sin hardcode). Cada pieza es testeable por separado: el servicio sin UI, el wizard sobre
el servicio.

**Flujo:** Wizard → `propose()` prellena (inventario/PPE/préstamos autoderivados, Capital plug) → usuario
ajusta Caja/Banco/CxC/CxP → preview de cuadre → `post()` → valida unicidad + cuadre → path apertura de
`JournalEntryService` → líneas + `ledger_account_daily` automático → saldos iniciales visibles en balance.

## Manejo de errores

- 2ª apertura para la misma gestión → excepción (guard `lockForUpdate` + índice DB).
- Capital plug garantiza cuadre; `validateLines` aborta si por algún motivo no cuadra.
- Reverso de apertura → bloqueado (R10).
- Período cerrado por path normal → sigue prohibido; solo el path apertura lo permite, una vez.
- Doble conteo de inventario (compras/ventas retroactivas ya posteadas) → aviso en el wizard (R9).

## Testing (PHPUnit class-style + RefreshDatabase, centavos int)

- **Cuadre:** `post()` con Activo=Pasivo+Patrimonio (ej. 320.500 = 10.000 + 310.500) crea asiento
  `entry_type=apertura`; con Capital plug siempre cuadra.
- **Unicidad:** 2ª apertura misma gestión lanza excepción; guard concurrente no duplica.
- **Saldos:** tras postear, `LedgerBalanceService::balancesAt(fecha)` da Caja=180.000·100, Inventario=90.500·100,
  Capital=310.500·100, y ΣActivo=ΣPasivo+Patrimonio.
- **Centavos:** 180.000 Bs → 18.000.000 en la línea.
- **Autoderivación:** `propose()` refleja Σ `FixedAsset::is_opening` y Σ `Loan::is_opening`.
- **Backfill:** posteo a período cerrado por path apertura funciona **una** vez; por path normal falla;
  comando/acción idempotente (2ª corrida no duplica).
- **Reverso bloqueado:** intentar revertir la apertura lanza excepción.
- **No romper venta/compra:** numeración de voucher de ventas intacta; posteo de venta sigue cuadrando.

## Fuera de alcance

- Jubilar `opening_balance_amount` (refactor lectores ROI/VAN en `FinancialStatementService`/`BudgetProjectionService`) → **follow-up**.
- Plan de cuentas multinivel + IVA/IT en asientos → **spec B**.
- Cierre de gestión (IUE, reserva legal, Estado de Resultados) → **spec C** (depende de esta apertura).
