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
                    <img src="{{ \Illuminate\Support\Facades\Storage::url($logoPath) }}" alt="Logo">
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
                <td class="col-disc">{{ $item->discount > 0 ? format_money($item->discount) : '-' }}</td>
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
                Impreso por: <strong>{{ auth()->user()?->name ?? 'Sistema' }}</strong>
                el {{ now()->format('d/m/Y H:i') }}
            </div>
        </div>

        <div class="footer-right">
            <div class="amount-row">
                <span class="amount-label">Subtotal</span>
                <span class="amount-value">@money($sale->subtotal)</span>
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
