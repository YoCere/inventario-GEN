# IVA/IT en los Asientos de Venta y Compra — Diseño

**Fecha:** 2026-08-27
**Estado:** Aprobado (diseño) — pendiente revisión de spec
**Parte de:** Mejoras contables del contador (ver `accounting-contador-mejoras` en memoria). Es **tema B,
pieza 1** (impuestos en asientos). La pieza 2 (plan de cuentas 4-5 niveles) es un spec aparte posterior.
Depende de F0 (columnas fiscales en `sales` + `SaleTaxCalculator`).

## Objetivo

Reflejar IVA e IT en los **asientos contables** de venta y compra **con factura**, leyendo los montos que
F0 ya calcula. La **venta rápida sin factura queda intacta**. Simple y enfocado: reusar el motor existente,
un solo colaborador nuevo, sin gold-plating.

## Asientos objetivo (ejemplos del contador)

**Venta Bs.100 con factura** (IVA 13, IT 3, costo 87):
```
DEBE  Caja/Banco 100 · Gasto IT 3 · Costo de Ventas 87
HABER Ventas 87 (= total − iva) · Débito Fiscal IVA 13 · IT por Pagar 3 · Inventario 87
```
Cuadre: DEBE 190 = HABER 190.

**Compra Bs.100 con factura** (IVA 13; IT es SOLO ventas):
```
DEBE  Inventario 87 (= total − iva) · Crédito Fiscal IVA 13
HABER Caja/CxP 100
```
Cuadre: DEBE 100 = HABER 100.

**Venta/compra SIN factura:** exactamente los asientos actuales, sin cuentas fiscales.

## Decisiones (fijadas con el usuario)

| Decisión | Elegido |
|---|---|
| Alcance | **Impuestos primero**; multinivel = spec aparte. |
| Cobertura | **Ventas + Compras** (compra necesita columnas fiscales nuevas + `PurchaseTaxCalculator`). |
| Reporte | El `buildTaxBreakdown` **lee saldos reales** de las cuentas fiscales (deja de estimar %). |

## Estado actual (con file:line)

- **F0 calcula pero el asiento ignora:** `SaleService` persiste `taxable_base/iva_amount/it_amount/wants_invoice`
  en `sales` vía `App\Fiscal\SaleTaxCalculator::forTotal()` (`app/Fiscal/SaleTaxCalculator.php:22-34`;
  columnas en `database/migrations/2026_07_20_170200_add_fiscal_columns_to_sales.php`). Pero
  `SaleAccountingService::postCompletedSale` (`app/Services/Accounting/SaleAccountingService.php:53-96`)
  postea 4 líneas con `$sale->total` completo, **sin leer** iva/it/wants_invoice → Ventas sobrevaluada, IT
  no registrado.
- `PurchaseAccountingService::postPurchase` (`app/Services/Accounting/PurchaseAccountingService.php:57-83`)
  postea Inventario=total / Caja|CxP=total, **sin CF-IVA**. `purchases` **no tiene columnas fiscales**
  (F0 solo tocó `sales`).
- **Cuentas fiscales ya sembradas sin usar:** DF-IVA `2.1.11`, CF-IVA `1.1.05`, IT por Pagar `2.1.12`
  (`database/seeders/ChartOfAccountSeeder.php`). **Falta cuenta de gasto IT.**
- **Estimación paralela:** `FinancialStatementService::buildTaxBreakdown` estima IVA/IT como % del total
  (`app/Services/FinancialStatementService.php:134-188`) → doble-contaría contra el impuesto real posteado.
- **Tasas inconsistentes:** `SaleTaxCalculator` default `tax_iva_rate/tax_it_rate` = **'0'** vs el reporte
  usa **'13'/'3'**. Hay que homologar.
- Mapeo de cuentas centralizado por `Setting::get('accounting_*_code', …)` (patrón
  `SaleAccountingService.php:40-49`).

## Requisitos

| # | Requisito |
|---|-----------|
| R1 | Migración aditiva idempotente: settings `accounting_df_iva_code`=2.1.11, `accounting_cf_iva_code`=1.1.05, `accounting_it_payable_code`=2.1.12, `accounting_it_expense_code`=**5.2.01**. Homologar tasas: sembrar `tax_iva_rate`=13, `tax_it_rate`=3 si no existen. |
| R2 | Sembrar en `ChartOfAccountSeeder` (idempotente `updateOrCreate`) la **cuenta de gasto IT** faltante: código **`5.2.01`**, nombre `Impuesto a las Transacciones`, `account_type=expense`, `normal_balance=debit`, `allows_posting=true`. Verificar/crear su cuenta padre de grupo si el patrón del seeder lo requiere. |
| R3 | `App\Services\Accounting\TaxLinesBuilder` — colaborador que arma las líneas fiscales a partir de montos ya calculados. `saleTaxLines(int $iva, int $it): array` (DF-IVA crédito, IT-gasto débito, IT-x-pagar crédito) y `purchaseTaxLines(int $iva): array` (CF-IVA débito). Resuelve cuentas por Setting. Devuelve líneas en el formato de `createPostedEntry`. **Cuadre al centavo**: el builder NO ajusta Ventas/Inventario (eso lo hace el Service reduciendo la línea base al neto); el builder solo emite las líneas de impuesto con los montos exactos de F0. |
| R4 | `SaleAccountingService::postCompletedSale`: si `$sale->wants_invoice` → línea Ventas = `total − iva_amount` (neto), y agregar `TaxLinesBuilder::saleTaxLines($sale->iva_amount, $sale->it_amount)`. Si `wants_invoice` es false/0 → asiento actual intacto (Ventas = total, sin cuentas fiscales). COGS/Inventario sin cambios. |
| R5 | Compra fiscal: migración aditiva agrega a `purchases` las columnas `wants_invoice` (bool default false), `taxable_base` (int default 0), `iva_amount` (int default 0). `App\Fiscal\PurchaseTaxCalculator::forTotal(int $total): array{taxable_base:int, iva_amount:int}` (solo IVA, tasa `tax_iva_rate`). `PurchaseService` calcula y persiste esos campos al crear/recibir la compra (espejo de cómo `SaleService` usa `SaleTaxCalculator`). |
| R6 | `PurchaseAccountingService::postPurchase`: si `$purchase->wants_invoice` → línea Inventario = `total − iva_amount` (neto) + `TaxLinesBuilder::purchaseTaxLines($purchase->iva_amount)` (CF-IVA débito). Contrapartida Caja/CxP = `total` (sin cambios). Si no → asiento actual intacto. **Sin IT en compras.** |
| R7 | `FinancialStatementService::buildTaxBreakdown`: reemplazar la estimación por % por la **lectura de saldos reales** de las cuentas fiscales (DF-IVA, CF-IVA, IT por Pagar) vía `LedgerBalanceService`. IVA a pagar = saldo DF-IVA − saldo CF-IVA; IT a pagar = saldo IT por Pagar. |

## Arquitectura

Reusa `JournalEntryService` (doble partida, centavos, reverso genérico) — las líneas fiscales son líneas
normales. La única pieza nueva es `TaxLinesBuilder`, un colaborador chico que ambos Services invocan para
no duplicar el mapeo de cuentas ni el manejo de montos. El gate es un solo `if ($x->wants_invoice)` en cada
Service; la venta rápida no pasa por ahí. El reporte pasa de estimar a leer los saldos posteados → fuente
única de verdad.

**Redondeo:** los montos de impuesto vienen de F0 (`round()` al centavo). El Service reduce la línea base
(Ventas/Inventario) al neto = `total − iva`; como todas las demás líneas usan montos exactos, el asiento
cuadra por construcción. Un test con total impar lo fija.

## Manejo de errores / invariantes

- **Venta rápida sin factura intacta** (los 4 líneas actuales) — invariante testeado.
- `validateLines` aborta si el asiento no cuadra.
- Ventas/compras históricas ya posteadas sin impuesto **no** se re-postean; solo aplica a nuevas con factura.
- El reverso (`reverseEntry`) invierte todas las líneas genéricamente → las líneas fiscales se revierten solas.
- Cuentas por Setting, sin hardcode.

## Testing (PHPUnit class-style + RefreshDatabase, centavos int)

- **Venta con factura:** asiento con las 7 líneas; DF-IVA=`iva` en 2.1.11, IT-gasto en su cuenta, IT-x-pagar=`it`
  en 2.1.12, Ventas=`total−iva`, Costo/Inventario=`cogs`; `Σdebe==Σhaber`.
- **Venta rápida SIN factura (invariante):** `wants_invoice=false` → 4 líneas, sin cuentas fiscales.
- **Compra con factura:** Inventario=`total−iva` + CF-IVA=`iva` en 1.1.05; Caja/CxP=`total`; cuadra; **sin IT**.
- **Redondeo:** total impar (p.ej. 99,99) → cuadra al centavo.
- **Reverso con factura:** revierte las líneas fiscales; saldos DF-IVA/CF-IVA/IT vuelven a 0.
- **Reporte sin doble-conteo:** con impuestos posteados, `buildTaxBreakdown` refleja los saldos reales (no estima).

## Fuera de alcance

- Plan de cuentas 4-5 niveles → **spec aparte** (tema B pieza 2).
- Cierre de gestión (IUE, reserva, EERR) → **tema C**.
- Re-posteo retroactivo de ventas/compras históricas.
