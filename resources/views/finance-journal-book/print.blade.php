<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Libro Diario — {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} al {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}</title>
    <style>
        @media print {
            @page {
                size: A4 portrait;
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
            max-width: 190mm;
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
            margin-bottom: 2px;
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

        /* ===== TABLE ===== */
        table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #999;
            margin-bottom: 8px;
            font-size: 8pt;
        }

        thead th {
            border: 1px solid #999;
            padding: 4px 3px;
            text-align: center;
            font-weight: bold;
            background: #e8e8e8;
            font-size: 7.5pt;
            white-space: nowrap;
            text-transform: uppercase;
        }

        td {
            border: 1px solid #ccc;
            padding: 3px 4px;
            font-size: 8pt;
            vertical-align: middle;
        }

        /* Column widths */
        .col-date    { width: 9%;  text-align: center; white-space: nowrap; }
        .col-code    { width: 13%; text-align: left;   font-family: monospace; font-size: 7.5pt; }
        .col-detail  { width: 52%; text-align: left; }
        .col-debit   { width: 13%; text-align: right;  font-family: monospace; white-space: nowrap; }
        .col-credit  { width: 13%; text-align: right;  font-family: monospace; white-space: nowrap; }

        /* Comprobante header row */
        .row-voucher {
            background: #d0d0d0;
            font-weight: bold;
            font-size: 7.5pt;
        }

        .row-voucher td {
            border: 1px solid #999;
            padding: 3px 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        /* Credit lines indented */
        .line-credit .col-detail {
            padding-left: 16px;
        }

        /* Glosa row */
        .row-glosa td {
            font-style: italic;
            font-size: 7.5pt;
            color: #555;
            border-bottom: none;
        }

        /* Subtotal row */
        .row-subtotal {
            background: #f0f0f0;
            font-weight: bold;
            font-size: 7.5pt;
        }

        .row-subtotal td {
            border: 1px solid #999;
            padding: 3px 4px;
        }

        .row-subtotal .label {
            text-align: right;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            font-size: 7pt;
            color: #555;
        }

        .underline-double {
            text-decoration: underline;
            text-decoration-style: double;
        }

        /* Spacer row */
        .row-spacer td {
            border: none;
            padding: 2px;
        }

        /* Totals footer */
        tfoot tr {
            background: #d0d0d0;
            font-weight: bold;
            font-size: 8.5pt;
        }

        tfoot td {
            border: 1px solid #999;
            padding: 5px 4px;
        }

        /* Empty state */
        .empty-row td {
            text-align: center;
            color: #777;
            font-style: italic;
            padding: 20px;
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
    </style>
</head>
<body>
@php
    $logoPath = \App\Models\Setting::get('shop_logo_path');
    $initials = strtoupper(substr($storeName, 0, 2));
    $fromFmt  = \Carbon\Carbon::parse($from)->format('d/m/Y');
    $toFmt    = \Carbon\Carbon::parse($to)->format('d/m/Y');
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
        <div class="report-title">Libro Diario</div>
        <div class="report-subtitle">(Expresado en Bolivianos)</div>
        <div class="report-subtitle">
            <strong>Período:</strong> {{ $fromFmt }} al {{ $toFmt }}
        </div>
        <div class="report-meta">
            Impreso por: <strong>{{ auth()->user()?->name ?? 'Sistema' }}</strong>
            &nbsp;&mdash;&nbsp;{{ now()->format('d/m/Y H:i') }}
        </div>
    </div>
</div>

<!-- Libro Diario Table -->
<table>
    <thead>
        <tr>
            <th class="col-date">Fecha</th>
            <th class="col-code">Código</th>
            <th class="col-detail">Detalle</th>
            <th class="col-debit">Debe</th>
            <th class="col-credit">Haber</th>
        </tr>
    </thead>
    <tbody>

        @forelse($rows as $entry)

            {{-- Comprobante header row --}}
            <tr class="row-voucher">
                <td class="col-date">{{ $entry['date'] }}</td>
                <td colspan="4">
                    COMPROBANTE DE {{ $entry['voucher_label'] }}
                    &nbsp;Nro:&nbsp;{{ $entry['voucher_number'] ?? '—' }}
                </td>
            </tr>

            {{-- Account lines --}}
            @foreach($entry['lines'] as $line)
                <tr class="{{ $line['debit'] == 0 ? 'line-credit' : '' }}">
                    <td class="col-date"></td>
                    <td class="col-code">{{ $line['code'] }}</td>
                    <td class="col-detail" style="{{ $line['debit'] == 0 ? 'padding-left:16px;' : '' }}">
                        {{ $line['name'] }}
                    </td>
                    <td class="col-debit">
                        @if($line['debit'] > 0){{ format_money($line['debit']) }}@endif
                    </td>
                    <td class="col-credit">
                        @if($line['credit'] > 0){{ format_money($line['credit']) }}@endif
                    </td>
                </tr>
            @endforeach

            {{-- Glosa --}}
            @if($entry['glosa'])
                <tr class="row-glosa">
                    <td class="col-date"></td>
                    <td class="col-code"></td>
                    <td colspan="3" style="font-style:italic; font-size:7.5pt; color:#555;">
                        {{ $entry['glosa'] }}
                    </td>
                </tr>
            @endif

            {{-- Subtotal --}}
            <tr class="row-subtotal">
                <td class="col-date"></td>
                <td class="col-code"></td>
                <td class="col-detail label" style="text-align:right; text-transform:uppercase; font-size:7pt; color:#555; letter-spacing:0.3px;">
                    Subtotal
                </td>
                <td class="col-debit underline-double">{{ format_money($entry['subtotal_debit']) }}</td>
                <td class="col-credit underline-double">{{ format_money($entry['subtotal_credit']) }}</td>
            </tr>

            {{-- Spacer --}}
            <tr class="row-spacer"><td colspan="5"></td></tr>

        @empty
            <tr class="empty-row">
                <td colspan="5">No hay asientos contabilizados en el período seleccionado.</td>
            </tr>
        @endforelse

    </tbody>

    @if(count($rows) > 0)
    <tfoot>
        <tr>
            <td colspan="3" style="text-align:right; font-weight:bold; background:#d0d0d0; text-transform:uppercase; letter-spacing:0.5px;">
                TOTALES
            </td>
            <td class="col-debit" style="background:#d0d0d0; text-align:right; font-family:monospace;">
                {{ format_money($totalDebit) }}
            </td>
            <td class="col-credit" style="background:#d0d0d0; text-align:right; font-family:monospace;">
                {{ format_money($totalCredit) }}
            </td>
        </tr>
    </tfoot>
    @endif
</table>

<!-- Page Footer -->
<div class="page-footer">
    <span>{{ $storeName }} &mdash; Libro Diario</span>
    <span>Período: {{ $fromFmt }} al {{ $toFmt }}</span>
    <span>Impreso el {{ now()->format('d/m/Y H:i') }}</span>
</div>

<script>
    window.onload = function () { window.print(); };
</script>
</body>
</html>
