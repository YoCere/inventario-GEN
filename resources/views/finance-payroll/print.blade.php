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
            <img src="{{ \Illuminate\Support\Facades\Storage::url($logoPath) }}" alt="Logo">
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
    Impreso por: <strong>{{ auth()->user()?->name ?? 'Sistema' }}</strong>
    el {{ now()->format('d/m/Y H:i') }}
    &nbsp;|&nbsp; {{ $storeName }}
</div>

<script>window.print();</script>
</body>
</html>
