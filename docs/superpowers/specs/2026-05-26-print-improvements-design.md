# Print Improvements Design
**Date:** 2026-05-26  
**Status:** Approved  

## Problem Statement

Multiple print views have issues:
1. Sales receipt says "FACTURA" instead of "RECIBO"
2. Finance transactions print shows amounts 100× too high (cents not divided by 100)
3. Finance transactions print has hardcoded "Rp" (Indonesian currency) prefix
4. Finance transactions print has hardcoded `Asia/Jakarta` timezone
5. No "Printed by" info on most print views
6. Libro Diario print shows navigation bar, blank ACCION column, cuts off Debe/Haber columns, description truncated
7. Kardex (12 columns) and Estados Financieros print the whole app layout including nav
8. Planilla print is bare/unstyled
9. CSS syntax error in `sales/print.blade.php` (orphaned `}` on line 118-119)

## Approved Approach: Option B

Fix all bugs + improve existing dedicated views + add `@media print` CSS to in-page reports.

**No new routes or controllers needed.**

## Files to Modify (7 total)

### 1. `resources/views/sales/print.blade.php`
- Change `<title>Factura` → `<title>Recibo`
- Change `FACTURA / COMPROBANTE / CONTADO N.º` → `RECIBO N.º`
- Fix CSS syntax error (remove orphaned `}` on lines 118-119)
- Change `@page { size: A5 landscape; }` → `size: letter landscape`
- Replace hardcoded `"TB"` logo box with company logo from `Setting::get('shop_logo_path')`
- Remove hardcoded description "Venta de: Materiales de Construcción..." — use `store_description` setting or remove
- Add "Impreso por: {{ auth()->user()->name }} el {{ now()->format('d/m/Y H:i') }}" in footer
- Pass `$printer = auth()->user()` from `SalesController::print()`

### 2. `resources/views/finance/reports/print.blade.php`
- **Bug fix:** Replace `number_format($cf->amount, 0, ',', '.')` with `format_money($cf->amount)` (line 128)
- **Bug fix:** Replace all `number_format($openingBalanceAmount, 0, ',', '.')`, `$totalIncome`, `$totalExpense`, `$estimatedFinalBalance` summary lines with `format_money()`
- **Bug fix:** Remove `Rp` prefix from summary row (line 158)
- **Bug fix:** Replace `'Asia/Jakarta'` timezone with `config('app.timezone')` (line 79)
- Add company logo from `Setting::get('shop_logo_path')`
- The "Por:" printed-by section already exists — just verify it uses correct user

### 3. `resources/views/finance-payroll/print.blade.php`
- Full redesign: professional layout with company header
- Add logo from `Setting::get('shop_logo_path')`
- Add store name, address, phone from Settings
- Add "Impreso por: [name] el [date time]" — passed from `PayrollController::print()`
- Better table styling: proper borders, zebra rows, bold totals
- Letter size vertical
- Pass `$printer = auth()->user()` from `PayrollController::print()`

### 4. `resources/views/finance-kardex/index.blade.php`
Add `<style>@media print { ... }</style>` block that:
- Hides: `nav`, `.navbar`, `[x-data]` navigation, page header buttons, filter form (`form` element), sidebar, footer
- Uses `@page { size: letter landscape; margin: 1cm; }`
- Adds print-only header div (`.print-header`) with logo, company name, "Kardex Valorizado", product name, period, "Impreso por: [name] [datetime]"
- The `.print-header` div is injected via JS `onbeforeprint` or statically hidden off-screen (`.print-only { display:none } @media print { .print-only { display:block } }`)
- Forces table font-size smaller (7pt) so 12 columns fit in landscape letter
- Hides the card wrapper chrome (border, padding classes)

### 5. `resources/views/finance-journal-entries/index.blade.php`
Add `<style>@media print { ... }</style>` block + static `.print-only` header div that:
- Hides: navigation, header buttons (Imprimir button itself), PowerGrid export/filter buttons, search input, pagination footer, ACCION column (`th:first-child`, `td:first-child` in PowerGrid table)
- Shows: `.print-only` header with logo, company name, "Libro Diario", date range, "Impreso por"
- `@page { size: letter portrait; margin: 1.5cm; }`
- Ensures Debe/Haber columns are visible (PowerGrid may need `white-space: nowrap` and no `overflow: hidden` on those cells)
- Per user: shows columns Número, Fecha, Descripción, Debe, Haber only
- Forces table-layout: fixed, distributes widths to show all selected columns fully
- Adds `window.onbeforeprint` JS to populate the "Impreso por" span with current user info (passed via Blade to a JS variable)

### 6. `resources/views/finance-statements/index.blade.php`
Add `<style>@media print { ... }</style>` block + static `.print-only` header div that:
- Hides: navigation, date filter form, Imprimir/Actualizar buttons
- Shows: `.print-only` header with logo, company name, "Estados Financieros", period, "Impreso por"
- `@page { size: letter portrait; margin: 1.5cm; }`
- Keeps all 6 financial statement sections (they already have `break-inside-avoid`)
- Better spacing/font for print

## Data Flow — "Impreso por"

| View | Method |
|------|--------|
| Sales print (dedicated) | `auth()->user()->name` in Blade (already auth-gated) |
| Finance reports print (dedicated) | `auth()->user()->name` in Blade (already exists line 81) |
| Payroll print (dedicated) | `auth()->user()->name` passed from controller |
| Kardex, Libro Diario, Estados (in-page) | Blade injects `<span id="print-user">{{ auth()->user()->name }}</span>` + `<span id="print-time">{{ now()->format('d/m/Y H:i') }}</span>` into a `.print-only` div |

## Logo

```blade
@php($logoPath = \App\Models\Setting::get('shop_logo_path'))
@if($logoPath)
    <img src="{{ asset('storage/' . $logoPath) }}" alt="Logo" style="height:50px; object-fit:contain;">
@else
    <div style="font-size:20pt; font-weight:bold;">{{ substr($storeName, 0, 2) }}</div>
@endif
```

## Formatting Convention

All monetary amounts use `format_money($amount)` from `app/Helpers/CurrencyHelper.php`.  
Never use raw `number_format()` on amounts that come from the database (stored as integer cents).

## Out of Scope

- No new routes or controllers
- No changes to `format_money()` helper
- No changes to data models
- No changes to non-print views (index pages stay the same)
