<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kardex Valorizado — {{ $report['product']->name }}</title>
    <style>
        @media print {
            @page {
                size: A4 landscape;
                margin: 1.5cm;
            }
            body {
                margin: 0;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 9pt;
            line-height: 1.3;
            color: #000;
            max-width: 270mm;
            margin: 0 auto;
            background: #fff;
            padding: 10px;
        }

        /* ===== HEADER ===== */
        .header {
            display: flex;
            width: 100%;
            margin-bottom: 8px;
            border-bottom: 2px solid #000;
            padding-bottom: 8px;
            align-items: flex-start;
        }

        .header-left {
            width: 55%;
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
            font-size: 20pt;
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
            font-size: 14pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 2px;
        }

        .company-address { font-size: 7.5pt; color: #333; }

        .header-right {
            width: 45%;
            text-align: right;
            padding-left: 15px;
        }

        .report-title {
            font-size: 14pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }

        .report-subtitle {
            font-size: 8pt;
            color: #333;
            margin-bottom: 2px;
        }

        .report-meta {
            font-size: 7.5pt;
            color: #555;
            margin-top: 6px;
        }

        /* ===== OPENING BALANCE ===== */
        .opening-balance {
            border: 1px solid #ccc;
            border-radius: 3px;
            padding: 6px 10px;
            margin-bottom: 8px;
            background: #f9f9f9;
            font-size: 8pt;
            display: flex;
            gap: 30px;
            align-items: center;
        }

        .opening-balance strong {
            font-size: 8.5pt;
        }

        .opening-item {
            display: flex;
            flex-direction: column;
        }

        .opening-label {
            font-size: 7pt;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .opening-value {
            font-size: 9pt;
            font-weight: bold;
        }

        /* ===== TABLE ===== */
        table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #999;
            margin-bottom: 8px;
            font-size: 7.5pt;
        }

        th {
            border: 1px solid #999;
            padding: 4px 3px;
            text-align: center;
            font-weight: bold;
            background: #e8e8e8;
            font-size: 7pt;
            white-space: nowrap;
        }

        td {
            border: 1px solid #ccc;
            padding: 3px 4px;
            font-size: 7.5pt;
            vertical-align: middle;
        }

        .group-header {
            background: #d0d0d0;
            text-align: center;
            font-weight: bold;
            font-size: 7pt;
            border: 1px solid #999;
            padding: 3px;
        }

        /* Column widths */
        .col-date      { width: 7%;  text-align: center; white-space: nowrap; }
        .col-detail    { width: 22%; text-align: left; }
        .col-ref       { width: 8%;  text-align: center; white-space: nowrap; }
        .col-entry-qty { width: 6%;  text-align: right; }
        .col-entry-cu  { width: 7%;  text-align: right; }
        .col-entry-imp { width: 9%;  text-align: right; }
        .col-exit-qty  { width: 6%;  text-align: right; }
        .col-exit-cu   { width: 7%;  text-align: right; }
        .col-exit-imp  { width: 9%;  text-align: right; }
        .col-bal-qty   { width: 6%;  text-align: right; font-weight: bold; }
        .col-bal-cu    { width: 7%;  text-align: right; font-weight: bold; }
        .col-bal-imp   { width: 9%;  text-align: right; font-weight: bold; }

        /* Totals row */
        tfoot tr {
            background: #e8e8e8;
            font-weight: bold;
        }

        tfoot td {
            border: 1px solid #999;
            padding: 4px;
        }

        /* Alternating rows */
        tbody tr:nth-child(even) { background: #fafafa; }

        /* ===== CLOSING BALANCE ===== */
        .closing-balance {
            border: 2px solid #333;
            border-radius: 3px;
            padding: 7px 12px;
            margin-top: 6px;
            background: #f0f0f0;
            display: flex;
            gap: 30px;
            align-items: center;
        }

        .closing-balance .section-label {
            font-size: 8.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .closing-item {
            display: flex;
            flex-direction: column;
        }

        .closing-label {
            font-size: 7pt;
            color: #555;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .closing-value {
            font-size: 10pt;
            font-weight: bold;
        }

        /* ===== FOOTER ===== */
        .page-footer {
            margin-top: 12px;
            border-top: 1px solid #ccc;
            padding-top: 5px;
            font-size: 7pt;
            color: #777;
            display: flex;
            justify-content: space-between;
        }

        /* Empty state */
        .empty-row td {
            text-align: center;
            color: #777;
            font-style: italic;
            padding: 12px;
        }
    </style>
</head>
<body>
@php
    $storeName    = \App\Models\Setting::get('store_name', config('app.name'));
    $storeAddress = \App\Models\Setting::get('store_address', '');
    $storePhone   = \App\Models\Setting::get('store_phone', '');
    $logoPath     = \App\Models\Setting::get('shop_logo_path');
    $initials     = strtoupper(substr($storeName, 0, 2));

    $product  = $report['product'];
    $opening  = $report['opening'];
    $rows     = $report['rows'];
    $totals   = $report['totals'];

    $fromFmt  = \Carbon\Carbon::parse($from)->format('d/m/Y');
    $toFmt    = \Carbon\Carbon::parse($to)->format('d/m/Y');

    // Closing unit cost (last row's balance_unit, or opening avg if no rows)
    $closingUnit = count($rows) > 0 ? $rows[count($rows) - 1]['balance_unit'] : $opening['avg'];
@endphp

<!-- Header -->
<div class="header">
    <div class="header-left">
        <div class="logo-box">
            @if($logoPath)
                <img src="{{ \Illuminate\Support\Facades\Storage::url($logoPath) }}" alt="Logo">
            @else
                {{ $initials }}
            @endif
        </div>
        <div class="company-info">
            <div class="company-name">{{ $storeName }}</div>
            <div class="company-address">
                @if($storeAddress){{ $storeAddress }}@endif
                @if($storePhone)<br>Tel. {{ $storePhone }}@endif
            </div>
        </div>
    </div>
    <div class="header-right">
        <div class="report-title">Kardex Valorizado</div>
        <div class="report-subtitle">
            <strong>Producto:</strong> {{ $product->name }}
            @if($product->sku)
                &nbsp;&mdash;&nbsp;<span style="font-family:monospace;">{{ $product->sku }}</span>
            @endif
        </div>
        <div class="report-subtitle">
            <strong>Periodo:</strong> {{ $fromFmt }} al {{ $toFmt }}
        </div>
        <div class="report-subtitle">
            <strong>Metodo:</strong> Promedio ponderado movil
        </div>
        <div class="report-meta">
            Impreso por: <strong>{{ auth()->user()?->name ?? 'Sistema' }}</strong>
            &nbsp;&mdash;&nbsp;{{ now()->format('d/m/Y H:i') }}
        </div>
    </div>
</div>

<!-- Opening Balance -->
<div class="opening-balance">
    <strong>Saldo Inicial:</strong>
    <div class="opening-item">
        <span class="opening-label">Cantidad</span>
        <span class="opening-value">{{ number_format($opening['qty'], 0, '.', ',') }}</span>
    </div>
    <div class="opening-item">
        <span class="opening-label">Costo unitario</span>
        <span class="opening-value">{{ number_format($opening['avg'], 4, '.', ',') }}</span>
    </div>
    <div class="opening-item">
        <span class="opening-label">Valor total</span>
        <span class="opening-value">{{ number_format($opening['value'], 2, '.', ',') }}</span>
    </div>
</div>

<!-- Kardex Table -->
<table>
    <thead>
        <tr>
            <th rowspan="2" class="col-date">Fecha</th>
            <th rowspan="2" class="col-detail">Detalle</th>
            <th rowspan="2" class="col-ref">Ref.</th>
            <th colspan="3" class="group-header">ENTRADAS</th>
            <th colspan="3" class="group-header">SALIDAS</th>
            <th colspan="3" class="group-header">SALDO</th>
        </tr>
        <tr>
            <th class="col-entry-qty">Cant.</th>
            <th class="col-entry-cu">C/U</th>
            <th class="col-entry-imp">Importe</th>
            <th class="col-exit-qty">Cant.</th>
            <th class="col-exit-cu">C/U</th>
            <th class="col-exit-imp">Importe</th>
            <th class="col-bal-qty">Cant.</th>
            <th class="col-bal-cu">C/U</th>
            <th class="col-bal-imp">Importe</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $row)
            <tr>
                <td class="col-date">{{ $row['date'] }}</td>
                <td class="col-detail">{{ $row['detail'] }}</td>
                <td class="col-ref">{{ $row['reference'] }}</td>
                <td class="col-entry-qty">{{ $row['entry_qty'] ? number_format($row['entry_qty'], 0, '.', ',') : '-' }}</td>
                <td class="col-entry-cu">{{ $row['entry_qty'] ? number_format($row['entry_unit'], 4, '.', ',') : '-' }}</td>
                <td class="col-entry-imp">{{ $row['entry_qty'] ? number_format($row['entry_total'], 2, '.', ',') : '-' }}</td>
                <td class="col-exit-qty">{{ $row['exit_qty'] ? number_format($row['exit_qty'], 0, '.', ',') : '-' }}</td>
                <td class="col-exit-cu">{{ $row['exit_qty'] ? number_format($row['exit_unit'], 4, '.', ',') : '-' }}</td>
                <td class="col-exit-imp">{{ $row['exit_qty'] ? number_format($row['exit_total'], 2, '.', ',') : '-' }}</td>
                <td class="col-bal-qty">{{ number_format($row['balance_qty'], 0, '.', ',') }}</td>
                <td class="col-bal-cu">{{ number_format($row['balance_unit'], 4, '.', ',') }}</td>
                <td class="col-bal-imp">{{ number_format($row['balance_total'], 2, '.', ',') }}</td>
            </tr>
        @empty
            <tr class="empty-row">
                <td colspan="12">No hay movimientos en el periodo seleccionado.</td>
            </tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr>
            <td colspan="3" style="text-align:right; font-weight:bold; background:#d0d0d0;">TOTALES</td>
            <td class="col-entry-qty" style="background:#d0d0d0;">{{ number_format($totals['entry_qty'], 0, '.', ',') }}</td>
            <td class="col-entry-cu" style="background:#d0d0d0; text-align:right;">—</td>
            <td class="col-entry-imp" style="background:#d0d0d0; text-align:right;">{{ number_format($totals['entry_total'], 2, '.', ',') }}</td>
            <td class="col-exit-qty" style="background:#d0d0d0;">{{ number_format($totals['exit_qty'], 0, '.', ',') }}</td>
            <td class="col-exit-cu" style="background:#d0d0d0; text-align:right;">—</td>
            <td class="col-exit-imp" style="background:#d0d0d0; text-align:right;">{{ number_format($totals['exit_total'], 2, '.', ',') }}</td>
            <td class="col-bal-qty" style="background:#d0d0d0;">{{ number_format($totals['closing_qty'], 0, '.', ',') }}</td>
            <td class="col-bal-cu" style="background:#d0d0d0; text-align:right;">—</td>
            <td class="col-bal-imp" style="background:#d0d0d0; text-align:right;">{{ number_format($totals['closing_total'], 2, '.', ',') }}</td>
        </tr>
    </tfoot>
</table>

<!-- Closing Balance -->
<div class="closing-balance">
    <span class="section-label">Saldo Final:</span>
    <div class="closing-item">
        <span class="closing-label">Cantidad</span>
        <span class="closing-value">{{ number_format($totals['closing_qty'], 0, '.', ',') }}</span>
    </div>
    <div class="closing-item">
        <span class="closing-label">Costo unitario promedio</span>
        <span class="closing-value">{{ number_format($closingUnit, 4, '.', ',') }}</span>
    </div>
    <div class="closing-item">
        <span class="closing-label">Valor total</span>
        <span class="closing-value">{{ number_format($totals['closing_total'], 2, '.', ',') }}</span>
    </div>
</div>

<!-- Page Footer -->
<div class="page-footer">
    <span>{{ $storeName }} &mdash; Kardex Valorizado</span>
    <span>Periodo: {{ $fromFmt }} al {{ $toFmt }} &mdash; Producto: {{ $product->name }} ({{ $product->sku }})</span>
    <span>Impreso el {{ now()->format('d/m/Y H:i') }}</span>
</div>

<script>
    window.onload = function () { window.print(); };
</script>
</body>
</html>
