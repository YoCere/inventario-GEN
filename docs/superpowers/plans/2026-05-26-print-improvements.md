# Print Improvements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all print views so receipts say "RECIBO", amounts are formatted correctly, every print shows who printed it and when, and in-page reports (Kardex, Libro Diario, Estados Financieros) hide navigation and UI chrome when printing.

**Architecture:** 7 Blade view files are modified. 3 already have dedicated print views (sales, finance transactions, payroll). 3 are in-page reports using `window.print()` that receive `@media print` CSS blocks + static `.print-only` header divs. No new routes, controllers, or models needed. All monetary amounts route through `format_money()` from `app/Helpers/CurrencyHelper.php`.

**Tech Stack:** Laravel 10+, Blade, Tailwind CSS (on in-page views), PowerGrid (Libro Diario), inline `@media print` CSS, `window.onbeforeprint` JS for dynamic "Impreso por".

---

## File Map

| File | Action | What changes |
|------|--------|-------------|
| `resources/views/sales/print.blade.php` | Modify | "RECIBO", carta landscape, logo, impreso-por, fix orphan CSS `}` |
| `resources/views/finance/reports/print.blade.php` | Modify | `format_money()` bug fix, remove "Rp", fix timezone |
| `resources/views/finance-payroll/print.blade.php` | Rewrite | Full redesign with company header + logo + impreso-por |
| `resources/views/finance-kardex/index.blade.php` | Modify | Add `<style>@media print</style>` + `.print-only` header div |
| `resources/views/finance-journal-entries/index.blade.php` | Modify | Add `<style>@media print</style>` + `.print-only` header div |
| `resources/views/finance-statements/index.blade.php` | Modify | Add `<style>@media print</style>` + `.print-only` header div |

---

## Task 1: Fix sales/print.blade.php

**Files:**
- Modify: `resources/views/sales/print.blade.php`

- [ ] **Step 1: Identify exact lines to change**

Open `resources/views/sales/print.blade.php`. Locate:
- Line 6: `<title>Factura N.º #{{ $sale->invoice_number }}</title>`
- Line 9: `size: A5 landscape;`
- Lines 118-119: orphan `}` (CSS syntax error — there is a duplicate closing brace after `.header-right .header-value` block)
- Line 245: `<div class="logo-box">TB</div>` (hardcoded initials)
- Line 248: `<div class="company-desc">Venta de: Materiales de Construcción...` (hardcoded description)
- Line 268: `<span class="invoice-label">FACTURA / COMPROBANTE / CONTADO N.º</span>`
- No "Impreso por" section exists in footer

- [ ] **Step 2: Replace the entire file content**

Replace `resources/views/sales/print.blade.php` with the following:

```blade
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recibo N.º #{{ $sale->invoice_number }}</title>
    <style>
        @media print {
            @page {
                size: letter landscape;
                margin: 0;
            }
            body {
                margin: 8mm 12mm;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            line-height: normal;
            color: #000;
            max-width: 260mm;
            margin: 0 auto;
            background: #fff;
            padding: 10px;
        }

        .container { width: 100%; }

        /* CABECERA */
        .header {
            display: flex;
            width: 100%;
            margin-bottom: 4px;
            border-bottom: 2px solid #000;
            padding-bottom: 6px;
        }

        .header-left {
            width: 60%;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo-box {
            border: 3px double #000;
            width: 60px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22pt;
            font-weight: bold;
            font-family: 'Times New Roman', serif;
            flex-shrink: 0;
            overflow: hidden;
        }

        .logo-box img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .company-info { text-align: left; }

        .company-name {
            font-family: 'Times New Roman', serif;
            font-size: 15pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 2px;
        }

        .company-address { font-size: 8pt; }

        .header-right {
            width: 40%;
            text-align: right;
            padding-left: 20px;
            font-size: 9pt;
        }

        .header-row {
            display: flex;
            margin-bottom: 5px;
            align-items: flex-end;
            justify-content: flex-end;
        }

        .header-label { white-space: nowrap; margin-right: 5px; }

        .header-value {
            border-bottom: 1px dotted #000;
            flex-grow: 0;
            min-width: 150px;
            padding-left: 5px;
        }

        /* LÍNEA DEL NÚMERO DE RECIBO */
        .invoice-row {
            margin-top: 3px;
            margin-bottom: 6px;
            font-weight: bold;
            font-size: 9pt;
            display: flex;
            align-items: center;
        }

        .invoice-label { margin-right: 5px; font-style: italic; }

        .invoice-value {
            border-bottom: 1px dotted #000;
            min-width: 100px;
            display: inline-block;
        }

        /* TABLA */
        table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #000;
            margin-bottom: 5px;
        }

        th {
            border: 1px solid #000;
            padding: 5px;
            text-align: center;
            font-weight: bold;
            background: #f0f0f0;
            font-size: 8pt;
            white-space: nowrap;
        }

        td {
            border-left: 1px solid #000;
            border-right: 1px solid #000;
            border-bottom: 1px solid #000;
            padding: 4px 5px;
            font-size: 8pt;
            vertical-align: middle;
            height: 20px;
        }

        .col-name { width: 45%; text-align: left; }
        .col-qty  { width: 8%;  text-align: center; }
        .col-price{ width: 16%; text-align: right; }
        .col-disc { width: 13%; text-align: right; }
        .col-total{ width: 18%; text-align: right; }

        /* PIE */
        .footer {
            display: flex;
            margin-top: 5px;
            align-items: flex-start;
        }

        .footer-left {
            width: 22%;
            text-align: center;
            font-size: 9pt;
        }

        .footer-center {
            width: 46%;
            padding: 0 10px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .disclaimer-box {
            border: 1px solid #000;
            border-radius: 5px;
            padding: 8px;
            font-size: 8pt;
            text-align: center;
            background: #f5f5f5;
            width: 100%;
        }

        .printed-by {
            font-size: 7.5pt;
            color: #555;
            text-align: center;
        }

        .footer-right { width: 32%; }

        .amount-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 10pt;
            font-weight: bold;
        }

        .amount-label { text-align: left; }

        .amount-value {
            text-align: right;
            border-bottom: 1px solid #ccc;
            min-width: 90px;
        }

        .signature-space { height: 40px; margin-top: 5px; }
    </style>
</head>
<body>
@php
    $storeName    = \App\Models\Setting::get('store_name', config('app.name'));
    $storeAddress = \App\Models\Setting::get('store_address', '');
    $storePhone   = \App\Models\Setting::get('store_phone', '');
    $logoPath     = \App\Models\Setting::get('shop_logo_path');
    $initials     = strtoupper(substr($storeName, 0, 2));
@endphp

<div class="container">
    <!-- Cabecera -->
    <div class="header">
        <div class="header-left">
            <div class="logo-box">
                @if($logoPath)
                    <img src="{{ asset('storage/' . $logoPath) }}" alt="Logo">
                @else
                    {{ $initials }}
                @endif
            </div>
            <div class="company-info">
                <div class="company-name">{{ $storeName }}</div>
                <div class="company-address">
                    {{ $storeAddress }}
                    @if($storePhone)
                        <br>Tel. {{ $storePhone }}
                    @endif
                </div>
            </div>
        </div>
        <div class="header-right">
            <div class="header-row">
                <span>{{ $sale->sale_date->locale('es')->isoFormat('dddd, D MMMM Y') }}</span>
            </div>
            <div class="header-row">
                <span class="header-label">Cliente:</span>
                <span class="header-value">{{ $sale->customer->name ?? 'Invitado' }}</span>
            </div>
        </div>
    </div>

    <!-- Número de Recibo -->
    <div class="invoice-row">
        <span class="invoice-label">RECIBO DE VENTA N.º</span>
        <span class="invoice-value">{{ $sale->invoice_number }}</span>
    </div>

    <!-- Tabla -->
    <table>
        <thead>
            <tr>
                <th class="col-name">Artículo</th>
                <th class="col-qty">Cant.</th>
                <th class="col-price">Precio Unit.</th>
                <th class="col-disc">Descuento</th>
                <th class="col-total">Importe</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->items as $item)
            <tr>
                <td class="col-name">{{ $item->product->name }}</td>
                <td class="col-qty">{{ $item->quantity }}</td>
                <td class="col-price">@money($item->unit_price)</td>
                <td class="col-disc">{!! $item->discount > 0 ? format_money($item->discount) : '-' !!}</td>
                <td class="col-total">@money($item->subtotal)</td>
            </tr>
            @endforeach
            @for($i = 0; $i < max(0, 8 - count($sale->items)); $i++)
            <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
            @endfor
        </tbody>
    </table>

    <!-- Pie de página -->
    <div class="footer">
        <div class="footer-left">
            <div>Recibí Conforme</div>
            <div class="signature-space"></div>
            <div>( .................................... )</div>
        </div>

        <div class="footer-center">
            <div class="disclaimer-box">
                Por favor verifique la mercancía al momento de recibirla. Los artículos vendidos no tienen devolución.
            </div>
            <div class="printed-by">
                Impreso por: <strong>{{ auth()->user()->name }}</strong>
                el {{ now()->format('d/m/Y H:i') }}
            </div>
        </div>

        <div class="footer-right">
            <div class="amount-row">
                <span class="amount-label">Subtotal</span>
                <span class="amount-value">@money($sale->total + $sale->global_discount)</span>
            </div>
            @if($sale->global_discount > 0)
            <div class="amount-row">
                <span class="amount-label">Descuento Extra</span>
                <span class="amount-value">- @money($sale->global_discount)</span>
            </div>
            @endif
            <div class="amount-row">
                <span class="amount-label">Total</span>
                <span class="amount-value">@money($sale->total)</span>
            </div>
            <div class="amount-row">
                <span class="amount-label">Recibido</span>
                <span class="amount-value">@money($sale->cash_received)</span>
            </div>
            <div class="amount-row">
                <span class="amount-label">Cambio</span>
                <span class="amount-value">@money($sale->change)</span>
            </div>
        </div>
    </div>
</div>
</body>
</html>
```

- [ ] **Step 3: Verify in browser**

Navigate to any sale → click "Imprimir" (or go to `/sales/{id}/print`).
Expected in browser print preview:
- Title says "RECIBO DE VENTA N.º"
- Logo shows (or 2-letter initials if no logo)
- No CSS errors in browser console
- Footer shows "Impreso por: [tu nombre] el [fecha hora]"
- Page fits on letter landscape without overflow

- [ ] **Step 4: Commit**

```bash
git add resources/views/sales/print.blade.php
git commit -m "fix(print): ventas — RECIBO, carta landscape, logo, impreso-por, fix CSS

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Task 2: Fix digit bug in finance/reports/print.blade.php

**Files:**
- Modify: `resources/views/finance/reports/print.blade.php`

The bug: amounts stored as integer cents (e.g. 30000 = 300.00 Bs) are passed raw to `number_format(..., 0, ',', '.')` which displays 30000 as "30.000" (looks like 30 thousand). The fix is to use `format_money()` which divides by 100.

- [ ] **Step 1: Fix amount formatting in the table rows**

In `resources/views/finance/reports/print.blade.php`, find line ~128:
```blade
<td class="text-right" style="font-family: monospace; font-size: 13px;">
    {{ number_format($cf->amount, 0, ',', '.') }}
</td>
```

Replace with:
```blade
<td class="text-right" style="font-family: monospace; font-size: 13px;">
    {{ format_money($cf->amount) }}
</td>
```

- [ ] **Step 2: Fix the summary section (remove "Rp", fix all amounts)**

Find the summary table block (~lines 141-160):
```blade
<div class="summary-section">
    <table class="summary-table">
        <tr>
            <td class="text-right" style="color: #666;">Saldo Inicial ({{ \Carbon\Carbon::parse($openingBalanceDate)->format('d M Y') }})</td>
            <td class="text-right">{{ number_format($openingBalanceAmount, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="text-right" style="color: #666;">Total de Ingresos</td>
            <td class="text-right" style="color: #065f46;">+ {{ number_format($totalIncome, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="text-right" style="color: #666;">Total de Gastos</td>
            <td class="text-right" style="color: #991b1b;">- {{ number_format($totalExpense, 0, ',', '.') }}</td>
        </tr>
        <tr class="summary-row-total">
            <td class="text-right">Saldo Final Estimado</td>
            <td class="text-right">Rp {{ number_format($estimatedFinalBalance, 0, ',', '.') }}</td>
        </tr>
    </table>
</div>
```

Replace with:
```blade
<div class="summary-section">
    <table class="summary-table">
        <tr>
            <td class="text-right" style="color: #666;">Saldo Inicial ({{ \Carbon\Carbon::parse($openingBalanceDate)->format('d M Y') }})</td>
            <td class="text-right">{{ format_money($openingBalanceAmount) }}</td>
        </tr>
        <tr>
            <td class="text-right" style="color: #666;">Total de Ingresos</td>
            <td class="text-right" style="color: #065f46;">+ {{ format_money($totalIncome) }}</td>
        </tr>
        <tr>
            <td class="text-right" style="color: #666;">Total de Gastos</td>
            <td class="text-right" style="color: #991b1b;">- {{ format_money($totalExpense) }}</td>
        </tr>
        <tr class="summary-row-total">
            <td class="text-right">Saldo Final Estimado</td>
            <td class="text-right">{{ format_money($estimatedFinalBalance) }}</td>
        </tr>
    </table>
</div>
```

- [ ] **Step 3: Fix hardcoded Jakarta timezone**

Find (~line 79):
```blade
Impreso: {{ now()->setTimezone('Asia/Jakarta')->translatedFormat('d F Y, H:i') }}
```

Replace with:
```blade
Impreso: {{ now()->setTimezone(config('app.timezone'))->translatedFormat('d F Y, H:i') }}
```

- [ ] **Step 4: Add company logo to header**

Find the header company info block (~line 66-72):
```blade
<div class="header-container">
    <div class="company-info">
        <h1>{{ $storeName }}</h1>
        <p>{{ $storeAddress }}</p>
        @if($storePhone !== '-')
            <p>Teléfono: {{ $storePhone }}</p>
        @endif
    </div>
```

Replace with:
```blade
<div class="header-container">
    <div class="company-info" style="display:flex; align-items:flex-start; gap:12px;">
        @php($logoPath = \App\Models\Setting::get('shop_logo_path'))
        @if($logoPath)
            <img src="{{ asset('storage/' . $logoPath) }}"
                 alt="Logo"
                 style="height:55px; object-fit:contain; flex-shrink:0;">
        @endif
        <div>
            <h1>{{ $storeName }}</h1>
            <p>{{ $storeAddress }}</p>
            @if($storePhone !== '-')
                <p>Teléfono: {{ $storePhone }}</p>
            @endif
        </div>
    </div>
```

- [ ] **Step 5: Verify in browser**

Print a finance transaction report. In the print preview:
- A sale of 300.00 Bs should appear as "300,00 Bs" (not "30.000")
- Summary totals should show normal amounts
- "Rp" prefix should be gone
- Logo should appear if configured

- [ ] **Step 6: Commit**

```bash
git add resources/views/finance/reports/print.blade.php
git commit -m "fix(print): transacciones — format_money bug, quitar Rp, timezone, logo

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Task 3: Redesign finance-payroll/print.blade.php

**Files:**
- Modify: `resources/views/finance-payroll/print.blade.php`

- [ ] **Step 1: Replace the entire file with a professional layout**

```blade
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planilla {{ $sheet->sheet_number }}</title>
    <style>
        @media print {
            @page { size: letter portrait; margin: 1.5cm; }
            body { margin: 0; padding: 0; }
            .no-print { display: none !important; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: #111;
            margin: 20px;
            background: #fff;
        }

        /* NO-PRINT BUTTON */
        .no-print {
            text-align: right;
            margin-bottom: 20px;
        }
        .btn-print {
            background: #2563eb; color: #fff; border: none;
            padding: 8px 20px; border-radius: 5px; cursor: pointer;
            font-size: 13px; font-weight: 600;
        }
        .btn-print:hover { background: #1d4ed8; }

        /* HEADER */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #222;
            padding-bottom: 14px;
            margin-bottom: 20px;
        }
        .company-block {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .company-block img {
            height: 55px;
            object-fit: contain;
            flex-shrink: 0;
        }
        .company-name {
            font-size: 18px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 3px;
        }
        .company-sub { font-size: 10px; color: #555; }
        .doc-meta { text-align: right; }
        .doc-title {
            font-size: 14px;
            font-weight: bold;
            text-transform: uppercase;
            color: #333;
            margin-bottom: 4px;
        }
        .doc-sub { font-size: 10px; color: #666; line-height: 1.6; }

        /* META GRID */
        .meta-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            background: #f8f8f8;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            padding: 10px 14px;
            margin-bottom: 18px;
            font-size: 10.5px;
        }
        .meta-grid strong { display: block; color: #888; font-size: 9.5px; text-transform: uppercase; margin-bottom: 2px; }

        /* TABLE */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        thead tr {
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
        }
        th {
            padding: 7px 6px;
            text-align: left;
            font-size: 9.5px;
            text-transform: uppercase;
            font-weight: bold;
            color: #000;
        }
        td {
            padding: 6px 6px;
            border-bottom: 1px solid #e5e5e5;
            font-size: 10.5px;
            vertical-align: middle;
        }
        tbody tr:nth-child(even) { background: #fafafa; }
        tfoot tr {
            border-top: 2px solid #333;
        }
        tfoot th {
            padding: 8px 6px;
            font-size: 10.5px;
            font-weight: bold;
        }
        .text-right { text-align: right; }
        .mono { font-family: monospace; }

        /* FOOTER */
        .print-footer {
            margin-top: 30px;
            border-top: 1px solid #ccc;
            padding-top: 8px;
            font-size: 9.5px;
            color: #666;
            text-align: center;
        }
    </style>
</head>
<body>
@php
    $storeName    = \App\Models\Setting::get('store_name', config('app.name'));
    $storeAddress = \App\Models\Setting::get('store_address', '');
    $storePhone   = \App\Models\Setting::get('store_phone', '');
    $logoPath     = \App\Models\Setting::get('shop_logo_path');
@endphp

<div class="no-print">
    <button onclick="window.print()" class="btn-print">🖨️ Imprimir Planilla</button>
</div>

<!-- HEADER -->
<div class="header">
    <div class="company-block">
        @if($logoPath)
            <img src="{{ asset('storage/' . $logoPath) }}" alt="Logo">
        @endif
        <div>
            <div class="company-name">{{ $storeName }}</div>
            @if($storeAddress)
                <div class="company-sub">{{ $storeAddress }}</div>
            @endif
            @if($storePhone)
                <div class="company-sub">Tel. {{ $storePhone }}</div>
            @endif
        </div>
    </div>
    <div class="doc-meta">
        <div class="doc-title">Planilla de Sueldos</div>
        <div class="doc-sub">
            N.º {{ $sheet->sheet_number }}<br>
            Periodo: {{ $sheet->period_month?->format('m/Y') ?? '-' }}<br>
            Pago: {{ $sheet->payment_date?->format('d/m/Y') ?? '-' }}
        </div>
    </div>
</div>

<!-- META -->
<div class="meta-grid">
    <div><strong>Estado</strong>{{ $sheet->status->label() }}</div>
    <div><strong>Periodo</strong>{{ $sheet->period_month?->translatedFormat('F Y') ?? '-' }}</div>
    <div><strong>Fecha de Pago</strong>{{ $sheet->payment_date?->format('d/m/Y') ?? '-' }}</div>
</div>

<!-- TABLE -->
<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Trabajador</th>
            <th>Área</th>
            <th class="text-right">Total Ganado</th>
            <th class="text-right">Total Desc.</th>
            <th class="text-right">Líquido Pagable</th>
            <th class="text-right">Costo Empleador</th>
        </tr>
    </thead>
    <tbody>
        @foreach($sheet->items as $item)
        <tr>
            <td>{{ $item->line_number }}</td>
            <td>{{ $item->employee_name }}</td>
            <td>{{ strtoupper($item->area) }}</td>
            <td class="text-right mono">{{ number_format($item->total_earned, 2, ',', '.') }}</td>
            <td class="text-right mono">{{ number_format($item->total_deductions, 2, ',', '.') }}</td>
            <td class="text-right mono">{{ number_format($item->net_payable, 2, ',', '.') }}</td>
            <td class="text-right mono">{{ number_format($item->total_employer_cost, 2, ',', '.') }}</td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <th colspan="3" class="text-right">TOTALES</th>
            <th class="text-right mono">{{ number_format($sheet->total_earned, 2, ',', '.') }}</th>
            <th class="text-right mono">{{ number_format($sheet->total_deductions, 2, ',', '.') }}</th>
            <th class="text-right mono">{{ number_format($sheet->net_payable, 2, ',', '.') }}</th>
            <th class="text-right mono">{{ number_format($sheet->total_employer_cost, 2, ',', '.') }}</th>
        </tr>
    </tfoot>
</table>

<!-- FOOTER -->
<div class="print-footer">
    Impreso por: <strong>{{ auth()->user()->name }}</strong>
    el {{ now()->format('d/m/Y H:i') }}
    &nbsp;|&nbsp; {{ $storeName }}
</div>

<script>window.print();</script>
</body>
</html>
```

- [ ] **Step 2: Verify in browser**

Go to a payroll sheet → "Imprimir". In print preview:
- Company header with logo (or name) appears
- Table has proper borders and totals row
- "Impreso por" line at bottom
- Fits on letter portrait

- [ ] **Step 3: Commit**

```bash
git add resources/views/finance-payroll/print.blade.php
git commit -m "fix(print): planilla — rediseño con cabecera empresa, logo, impreso-por

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Task 4: Print CSS for Kardex (finance-kardex/index.blade.php)

**Files:**
- Modify: `resources/views/finance-kardex/index.blade.php`

The Kardex page uses `window.print()` on the app layout. We add a `.print-only` div (hidden on screen) + `@media print` CSS to hide the navigation and show a clean header.

- [ ] **Step 1: Add the `.print-only` header div just before `<div class="py-4">`**

In `resources/views/finance-kardex/index.blade.php`, find:
```blade
    <div class="py-4">
```

Insert this block immediately before it:
```blade
    {{-- Print-only header (hidden on screen, shown during print) --}}
    <div id="kardex-print-header" style="display:none;">
        @php
            $storeName    = \App\Models\Setting::get('store_name', config('app.name'));
            $storeAddress = \App\Models\Setting::get('store_address', '');
            $storePhone   = \App\Models\Setting::get('store_phone', '');
            $logoPath     = \App\Models\Setting::get('shop_logo_path');
        @endphp
        <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #000; padding-bottom:10px; margin-bottom:12px;">
            <div style="display:flex; align-items:center; gap:10px;">
                @if($logoPath)
                    <img src="{{ asset('storage/' . $logoPath) }}" style="height:50px; object-fit:contain;">
                @endif
                <div>
                    <div style="font-size:16pt; font-weight:bold; text-transform:uppercase;">{{ $storeName }}</div>
                    @if($storeAddress)<div style="font-size:9pt; color:#555;">{{ $storeAddress }}</div>@endif
                    @if($storePhone)<div style="font-size:9pt; color:#555;">Tel. {{ $storePhone }}</div>@endif
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:13pt; font-weight:bold; text-transform:uppercase;">Kardex Valorizado</div>
                <div style="font-size:9pt; color:#555; margin-top:4px;">
                    Impreso por: <strong>{{ auth()->user()->name }}</strong><br>
                    el {{ now()->format('d/m/Y H:i') }}
                </div>
            </div>
        </div>
    </div>

    <div class="py-4">
```

- [ ] **Step 2: Add the `@media print` style block at the bottom of the file**

Find the closing `</x-app-layout>` tag. Just before it, add:

```blade
    <style>
        /* ===== KARDEX PRINT STYLES ===== */
        @media print {
            @page { size: letter landscape; margin: 1cm; }

            /* Hide everything except our content */
            nav, header, footer, .print\:hidden,
            form, [wire\:id], .no-print { display: none !important; }

            /* Show the print header */
            #kardex-print-header { display: block !important; }

            /* Reset body */
            body { background: #fff !important; padding: 0 !important; margin: 0 !important; }
            .py-4 { padding: 0 !important; }
            .max-w-7xl { max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
            .bg-card, .border, .rounded-lg { background: transparent !important; border: none !important; border-radius: 0 !important; padding: 0 !important; }

            /* Compact table for 12 columns in landscape */
            table { font-size: 7pt !important; border-collapse: collapse !important; width: 100% !important; }
            th, td { padding: 3px 4px !important; border: 1px solid #999 !important; white-space: nowrap !important; }
            thead { background: #f0f0f0 !important; }

            /* Hide form filter buttons that are not print:hidden */
            .sm\:px-6, .lg\:px-8 { padding: 0 !important; }
        }
    </style>
```

- [ ] **Step 3: Verify in browser**

Go to Kardex → genera un reporte → click "Imprimir". In print preview:
- Navigation bar hidden
- Filter form hidden
- Print header shows company name + logo + "Impreso por"
- Table is compact and fits in landscape letter
- All 12 columns visible

- [ ] **Step 4: Commit**

```bash
git add resources/views/finance-kardex/index.blade.php
git commit -m "fix(print): kardex — ocultar nav/filtros, cabecera empresa, landscape

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Task 5: Print CSS for Libro Diario (finance-journal-entries/index.blade.php)

**Files:**
- Modify: `resources/views/finance-journal-entries/index.blade.php`

The PowerGrid table renders columns in order: ACCION (button), ASIENTO, FECHA, DESCRIPCION, PERIODO, ESTADO, DEBE, HABER, CREADO POR. We need to hide ACCION column and several others, keep only Nº, Fecha, Descripción, Debe, Haber. PowerGrid wraps `<table>` inside `[wire:id]` divs. We target with CSS.

- [ ] **Step 1: Add the `.print-only` header div**

In `resources/views/finance-journal-entries/index.blade.php`, find:
```blade
    <div class="py-4">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <livewire:finance-journal-entries.journal-entry-table />
```

Insert this block immediately before `<div class="py-4">`:
```blade
    {{-- Print-only header --}}
    <div id="diario-print-header" style="display:none;">
        @php
            $storeName    = \App\Models\Setting::get('store_name', config('app.name'));
            $storeAddress = \App\Models\Setting::get('store_address', '');
            $storePhone   = \App\Models\Setting::get('store_phone', '');
            $logoPath     = \App\Models\Setting::get('shop_logo_path');
        @endphp
        <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #000; padding-bottom:10px; margin-bottom:12px;">
            <div style="display:flex; align-items:center; gap:10px;">
                @if($logoPath)
                    <img src="{{ asset('storage/' . $logoPath) }}" style="height:50px; object-fit:contain;">
                @endif
                <div>
                    <div style="font-size:16pt; font-weight:bold; text-transform:uppercase;">{{ $storeName }}</div>
                    @if($storeAddress)<div style="font-size:9pt; color:#555;">{{ $storeAddress }}</div>@endif
                    @if($storePhone)<div style="font-size:9pt; color:#555;">Tel. {{ $storePhone }}</div>@endif
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:13pt; font-weight:bold; text-transform:uppercase;">Libro Diario</div>
                <div style="font-size:9pt; color:#555; margin-top:4px;">
                    Impreso por: <strong>{{ auth()->user()->name }}</strong><br>
                    el {{ now()->format('d/m/Y H:i') }}
                </div>
            </div>
        </div>
    </div>

    <div class="py-4">
```

- [ ] **Step 2: Replace the existing `<style>` block at the bottom**

Find:
```blade
    <style>
        @media print {
            .print\:hidden { display: none !important; }
        }
    </style>
```

Replace with:
```blade
    <style>
        /* ===== LIBRO DIARIO PRINT STYLES ===== */
        @media print {
            @page { size: letter portrait; margin: 1.5cm; }

            /* Hide chrome */
            nav, header > *, footer,
            .print\:hidden,
            [x-data] > button,
            [class*="pg-"] .pg-header,
            [class*="pg-"] .pg-footer,
            [class*="pg-"] [class*="export"],
            [class*="pg-"] [class*="search"],
            [class*="pg-"] [class*="filter"],
            [class*="pg-"] [class*="per-page"],
            [class*="pg-"] [class*="pagination"],
            [class*="pg-"] [class*="record-count"] { display: none !important; }

            /* Show print header */
            #diario-print-header { display: block !important; }

            body { background: #fff !important; margin: 0 !important; padding: 0 !important; }
            .py-4, .max-w-7xl { padding: 0 !important; max-width: 100% !important; margin: 0 !important; }
            .bg-card, .border, .rounded-lg { background: transparent !important; border: none !important; padding: 0 !important; }

            /* Table styles */
            table { font-size: 9pt !important; border-collapse: collapse !important; width: 100% !important; table-layout: fixed !important; }
            th, td { padding: 5px 6px !important; border: 1px solid #aaa !important; overflow: hidden !important; text-overflow: ellipsis !important; }
            thead { background: #f0f0f0 !important; }
            thead th { font-size: 8pt !important; text-transform: uppercase !important; }

            /*
             * PowerGrid column order (0-based):
             * 0=ACCION  1=ASIENTO  2=FECHA  3=DESCRIPCION  4=PERIODO  5=ESTADO  6=DEBE  7=HABER  8=CREADO POR
             * Keep: 1(ASIENTO), 2(FECHA), 3(DESCRIPCION), 6(DEBE), 7(HABER)
             * Hide: 0, 4, 5, 8
             */
            table th:nth-child(1), table td:nth-child(1),   /* ACCION */
            table th:nth-child(5), table td:nth-child(5),   /* PERIODO */
            table th:nth-child(6), table td:nth-child(6),   /* ESTADO */
            table th:nth-child(9), table td:nth-child(9) { display: none !important; }   /* CREADO POR */

            /* Column widths for remaining 5 columns */
            table th:nth-child(2), table td:nth-child(2) { width: 18% !important; } /* ASIENTO */
            table th:nth-child(3), table td:nth-child(3) { width: 14% !important; } /* FECHA */
            table th:nth-child(4), table td:nth-child(4) { width: 44% !important; } /* DESCRIPCION */
            table th:nth-child(7), table td:nth-child(7) { width: 12% !important; } /* DEBE */
            table th:nth-child(8), table td:nth-child(8) { width: 12% !important; } /* HABER */
        }
    </style>
```

- [ ] **Step 3: Verify in browser**

Go to Libro Diario → click "Imprimir". In print preview:
- Navigation hidden
- Print header shows company + logo + "Impreso por"
- Table shows only: ASIENTO, FECHA, DESCRIPCION, DEBE, HABER
- ACCION column (buttons) hidden
- Description text is fully visible, not cut off
- All rows visible (current page)

- [ ] **Step 4: Commit**

```bash
git add resources/views/finance-journal-entries/index.blade.php
git commit -m "fix(print): libro diario — ocultar ACCION/nav, cabecera empresa, columnas limpias

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Task 6: Print CSS for Estados Financieros (finance-statements/index.blade.php)

**Files:**
- Modify: `resources/views/finance-statements/index.blade.php`

- [ ] **Step 1: Add the `.print-only` header div**

In `resources/views/finance-statements/index.blade.php`, find:
```blade
    <div class="py-4 space-y-6">
```

Insert this block immediately before it:
```blade
    {{-- Print-only header --}}
    <div id="estados-print-header" style="display:none;">
        @php
            $storeName    = \App\Models\Setting::get('store_name', config('app.name'));
            $storeAddress = \App\Models\Setting::get('store_address', '');
            $storePhone   = \App\Models\Setting::get('store_phone', '');
            $logoPath     = \App\Models\Setting::get('shop_logo_path');
        @endphp
        <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #000; padding-bottom:10px; margin-bottom:16px;">
            <div style="display:flex; align-items:center; gap:10px;">
                @if($logoPath)
                    <img src="{{ asset('storage/' . $logoPath) }}" style="height:50px; object-fit:contain;">
                @endif
                <div>
                    <div style="font-size:16pt; font-weight:bold; text-transform:uppercase;">{{ $storeName }}</div>
                    @if($storeAddress)<div style="font-size:9pt; color:#555;">{{ $storeAddress }}</div>@endif
                    @if($storePhone)<div style="font-size:9pt; color:#555;">Tel. {{ $storePhone }}</div>@endif
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:13pt; font-weight:bold; text-transform:uppercase;">Estados Financieros</div>
                <div style="font-size:9pt; color:#555; margin-top:4px;">
                    Periodo: {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} — {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}<br>
                    Impreso por: <strong>{{ auth()->user()->name }}</strong>
                    el {{ now()->format('d/m/Y H:i') }}
                </div>
            </div>
        </div>
    </div>

    <div class="py-4 space-y-6">
```

- [ ] **Step 2: Replace the existing `@media print` style block at the bottom**

Find:
```blade
    <style>
        @media print {
            .print\:hidden { display: none !important; }
            @page { size: landscape; margin: 1cm; }
            .break-inside-avoid { break-inside: avoid; page-break-inside: avoid; }
        }
    </style>
```

Replace with:
```blade
    <style>
        /* ===== ESTADOS FINANCIEROS PRINT STYLES ===== */
        @media print {
            @page { size: letter portrait; margin: 1.5cm; }

            /* Hide chrome */
            nav, header, footer,
            .print\:hidden { display: none !important; }

            /* Show print header */
            #estados-print-header { display: block !important; }

            body { background: #fff !important; margin: 0 !important; padding: 0 !important; color: #000 !important; }

            /* Remove card chrome */
            .py-4, .max-w-7xl { padding: 0 !important; max-width: 100% !important; margin: 0 !important; }
            .bg-card { background: transparent !important; }
            .border, .rounded-lg { border: none !important; border-radius: 0 !important; }

            /* Section titles */
            h3 { font-size: 11pt !important; border-bottom: 1px solid #ccc !important; padding-bottom: 4px !important; margin-bottom: 10px !important; }

            /* List items */
            .flex.justify-between { display: flex !important; justify-content: space-between !important; }

            /* Keep sections together */
            .break-inside-avoid {
                break-inside: avoid !important;
                page-break-inside: avoid !important;
                margin-bottom: 20px !important;
                padding: 10px 0 !important;
                border-bottom: 1px solid #e0e0e0 !important;
            }

            /* Indicator cards */
            .border.border-border.rounded-md { border: 1px solid #ccc !important; padding: 8px !important; border-radius: 4px !important; }

            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
```

- [ ] **Step 3: Verify in browser**

Go to Estados Financieros → click "Imprimir". In print preview:
- Navigation + filter form hidden
- Print header shows company + period + "Impreso por"
- All 6 sections visible: Balance General, Estado de Resultados, ROI/TIR, Patrimonio, Flujo de Efectivo, Notas
- Sections don't break in the middle of a section
- Letter portrait format

- [ ] **Step 4: Commit**

```bash
git add resources/views/finance-statements/index.blade.php
git commit -m "fix(print): estados financieros — ocultar nav/form, cabecera empresa, page breaks

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage check:**
- ✅ "RECIBO" instead of "FACTURA" — Task 1
- ✅ Amount digit bug (÷100) — Task 2  
- ✅ "Rp" Indonesian prefix removed — Task 2
- ✅ Hardcoded `Asia/Jakarta` timezone — Task 2
- ✅ CSS syntax error in sales/print.blade.php — Task 1 (full rewrite)
- ✅ Company logo on all print views — Tasks 1-6 (all use `Setting::get('shop_logo_path')`)
- ✅ "Impreso por: [nombre] el [fecha hora]" on all views — Tasks 1-6
- ✅ Planilla redesign — Task 3
- ✅ Kardex: hide nav/filters, landscape, 12 columns fit — Task 4
- ✅ Libro Diario: hide ACCION, nav, show 5 columns only — Task 5
- ✅ Estados Financieros: hide nav/form, page breaks — Task 6

**Placeholder scan:** No TBD, no "implement later", all code blocks complete.

**Type consistency:** `Setting::get('shop_logo_path')` used consistently in all 6 tasks. `format_money()` used for all amounts in Tasks 1 and 2. `auth()->user()->name` used consistently. `now()->format('d/m/Y H:i')` used consistently.
