# Estado de Resultados con CMV, IUE y Reserva Legal (C1) — Diseño

**Fecha:** 2026-09-01
**Estado:** Aprobado (diseño) — pendiente revisión de spec
**Parte de:** Mejoras contables del contador (ver `accounting-contador-mejoras` en memoria). Es **tema C,
pieza 1** (presentación). La pieza 2 (asientos de cierre: provisión IUE, reserva, cierre de resultados)
es un spec aparte. Depende de A (apertura, para capital/inventario inicial) y B1 (IVA/IT), ambos en main.

## Objetivo

Presentar el **Estado de Resultados con la estructura del contador**: CMV, utilidad bruta, gastos, IUE
25%, reserva legal 5% (condicional), utilidad de la gestión. **Solo cálculo y presentación — NO postea
ningún asiento** (eso es C2). Simple y enfocado.

## Decisiones (fijadas con el usuario)

| Decisión | Elegido |
|---|---|
| Alcance | **Presentación primero (C1)**; asientos de cierre = C2 aparte. |
| Tipo societario | **Configurable** por setting `company_entity_type` (default `unipersonal`); condiciona la reserva legal. |

## Estructura objetivo (ejemplo del contador)

```
Ventas
(−) CMV  [= Inv. Inicial + Compras − Dev. compras − Inv. Final]
= Utilidad Bruta
(−) Gastos de Operación
(+) Otros Ingresos
= Utilidad antes de Impuestos
(−) IUE 25%
= Utilidad después de Impuestos
(−) Reserva Legal 5%   ← solo S.A./S.R.L., tope 50% del capital
= Utilidad de la Gestión
```

## Estado actual (con file:line)

- `FinancialStatementService::build(from, to, withTaxes)` (`app/Services/FinancialStatementService.php:22-56`)
  ya calcula `periodBalances` (movimientos del período), `cumulativeBalances` (saldo acumulado a `to`) y
  `openingBalances` (saldo al día anterior a `from`).
- `buildIncomeStatement(Collection $periodBalances, bool $withTaxes)` (`:105-129`) devuelve income/cost/
  expense totals + `net_result` + `taxes` (que **desde B1 lee saldos fiscales reales**) + `net_result_after_tax`.
- Los balances traen `->code`, `->account_type`, `->balance` (int, firmado por normal_balance).
- **NO existe** cálculo de CMV desglosado, ni utilidad bruta, ni IUE, ni reserva legal, ni el setting
  `company_entity_type` (grep sin resultados).
- Cuentas relevantes sembradas: Ventas `4.1`, Costo de Ventas `5.1`, Inventario `1.1.04`, Capital `3.1`
  (`database/seeders/ChartOfAccountSeeder.php`). **No hay cuenta de Reserva Legal** — para C1 su saldo
  actual se toma como 0 si la cuenta no existe (la cuenta se crea en C2 cuando se postee).

## Requisitos

| # | Requisito |
|---|-----------|
| R1 | Migración aditiva idempotente + settings editables en Ajustes: `company_entity_type` (default `unipersonal`; valores `unipersonal`\|`persona_natural`\|`srl`\|`sa`), `tax_iue_rate`=`25`, `legal_reserve_rate`=`5`, `legal_reserve_cap_pct`=`50`. |
| R2 | Bloque **CMV** en el Estado de Resultados: `inventario_inicial` (saldo de `1.1.04` en `openingBalances`), `compras_periodo` (débitos a `1.1.04` en `periodBalances`), `inventario_final` (saldo de `1.1.04` en `cumulativeBalances`), y `cmv_total` = `cost_total` (saldo real de `5.1`, el CMV perpetuo). El desglose Inv.Inicial/Compras/Inv.Final es **informativo/reconciliación**; el CMV que resta es `cmv_total`. Sin cuenta de "devoluciones de compra" (no se inventa; queda 0). |
| R3 | Cadena de utilidad en `estado_resultados`: `ventas` (= suma de cuentas income con código `4.1*`), `utilidad_bruta` = `ventas − cmv_total`, `otros_ingresos` (= income no-`4.1`), `gastos_operacion` = `expense_total`, `utilidad_antes_impuestos` = `utilidad_bruta − gastos_operacion + otros_ingresos` (algebraicamente = `net_result`), `iue`, `utilidad_despues_impuestos`, `reserva_legal`, `utilidad_gestion`. |
| R4 | **IUE**: `iue` = `utilidad_antes_impuestos > 0 ? round(utilidad_antes_impuestos × tax_iue_rate / 100) : 0`. Pérdida → 0. `utilidad_despues_impuestos` = `utilidad_antes_impuestos − iue`. |
| R5 | **Reserva legal** (condicional): si `company_entity_type ∈ {srl, sa}` **y** `utilidad_despues_impuestos > 0` → `reserva = min( round(utilidad_despues_impuestos × legal_reserve_rate / 100), max(tope − reserva_actual, 0) )`, donde `tope = round(capital × legal_reserve_cap_pct / 100)`, `capital` = saldo de `3.1` en `cumulativeBalances`, `reserva_actual` = saldo de la cuenta de reserva legal en `cumulativeBalances` (0 si no existe). Unipersonal/persona natural → `reserva = 0`. `utilidad_gestion` = `utilidad_despues_impuestos − reserva`. |
| R6 | La **IVA/IT** de B1 (`taxes`/`iva_determinado`) **NO entra en la cadena de utilidad** (es pasivo de balance); se mantiene en el bloque `taxes` como información. `utilidad_antes_impuestos` usa `net_result` (que ya incluye el IT-gasto 6.7 como gasto), no `net_result_after_tax`. |
| R7 | Vista `resources/views/finance-statements/index.blade.php`: mostrar la estructura nueva (Ventas → CMV desglosado → Utilidad Bruta → Gastos → Otros Ingresos → Utilidad antes de Imp. → IUE → Utilidad desp. de Imp. → Reserva Legal → Utilidad de la Gestión). La línea de **reserva legal solo se muestra** si el tipo societario la aplica (o se muestra en 0 con nota). Respetar dark mode y el patrón de la vista existente. |

## Arquitectura

Todo vive en `FinancialStatementService` (extender `build`/`buildIncomeStatement` + un helper `buildCmv`)
y la vista. `build()` ya tiene las tres colecciones de saldos necesarias; se extraen los saldos de
`1.1.04`/`3.1`/reserva por código y se pasan a la construcción del EERR. Sin nuevos servicios ni asientos.
Cálculo puro y testeable: dado un set de saldos, la estructura de salida es determinista.

## Manejo de errores / bordes

- Pérdida (utilidad ≤ 0) → IUE 0 y reserva 0.
- Cuenta de reserva legal inexistente → `reserva_actual` = 0.
- Tope de reserva alcanzado (`reserva_actual ≥ tope`) → reserva del período = 0.
- Capital 0 (sin apertura cargada) → tope 0 → reserva 0 (degrada, no rompe).
- Tasas por setting; sin hardcode.

## Testing (PHPUnit + RefreshDatabase, centavos int)

- Utilidad positiva → `iue = 25% × utilidad_antes`; pérdida → `iue = 0`.
- `company_entity_type=unipersonal` con utilidad > 0 → `reserva_legal = 0`.
- `company_entity_type=srl` con utilidad > 0 → `reserva_legal = 5% × utilidad_despues`, respetando el tope 50% capital.
- Tope: `reserva_actual` cerca del tope → solo se apropia `tope − reserva_actual`.
- CMV: `cmv_total == cost_total`; el bloque expone Inv.Inicial/Compras/Inv.Final.
- `utilidad_antes_impuestos == net_result` (consistencia con el cálculo base).
- La vista renderiza la estructura para admin (test de render con el layout).

## Fuera de alcance (C2 y otros)

- Postear asientos (provisión IUE, reserva legal, cierre de resultados) → **C2**.
- Orquestación de cierre anual vs mensual, período cerrado → **C2**.
- Crear la cuenta contable de Reserva Legal → **C2** (cuando se postee).
- Plan de cuentas multinivel → tema **B2**.
