<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informe de Productos — {{ $storeName }}</title>
    <style>
        /* ===================================================================
           BASE
        =================================================================== */
        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 11px;
            line-height: 1.5;
            color: #1a1a1a;
            margin: 0;
            padding: 32px 40px;
            background: #fff;
        }

        /* ===================================================================
           CABECERA
        =================================================================== */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #1a1a1a;
            padding-bottom: 16px;
            margin-bottom: 24px;
        }

        .company-block {
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }

        .company-logo {
            height: 56px;
            width: auto;
            object-fit: contain;
            flex-shrink: 0;
        }

        .company-name {
            font-size: 18px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #111;
            margin: 0 0 3px 0;
        }

        .company-detail {
            font-size: 10px;
            color: #555;
            margin: 0;
            line-height: 1.4;
        }

        .report-meta {
            text-align: right;
        }

        .report-title {
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #111;
            margin-bottom: 4px;
        }

        .meta-line {
            font-size: 10px;
            color: #555;
            margin: 1px 0;
        }

        /* ===================================================================
           SECCIONES
        =================================================================== */
        .section {
            margin-bottom: 36px;
            page-break-inside: avoid;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f0f0f0;
            border-left: 4px solid #1a1a1a;
            padding: 6px 10px;
            margin-bottom: 10px;
        }

        .section-header.low-stock  { border-color: #b45309; background: #fef3c7; }
        .section-header.top        { border-color: #1d4ed8; background: #dbeafe; }
        .section-header.no-sales   { border-color: #6b7280; background: #f3f4f6; }
        .section-header.buy        { border-color: #15803d; background: #dcfce7; }

        .section-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #111;
        }

        .section-badge {
            font-size: 10px;
            font-weight: 600;
            background: rgba(0,0,0,.12);
            border-radius: 10px;
            padding: 1px 7px;
            color: #333;
        }

        .section-note {
            font-size: 10px;
            color: #666;
            margin: 0 0 8px 0;
            font-style: italic;
        }

        /* ===================================================================
           TABLAS
        =================================================================== */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
        }

        thead th {
            border-top: 1.5px solid #111;
            border-bottom: 1.5px solid #111;
            padding: 6px 6px;
            text-align: left;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #111;
            background: transparent;
        }

        tbody td {
            padding: 5px 6px;
            border-bottom: 1px solid #ddd;
            vertical-align: top;
            font-size: 11px;
            color: #1a1a1a;
        }

        tbody tr:last-child td {
            border-bottom: 1.5px solid #111;
        }

        tfoot td {
            padding: 7px 6px;
            font-weight: 700;
            font-size: 11px;
            border-top: 1.5px solid #111;
        }

        .text-right  { text-align: right; }
        .text-center { text-align: center; }

        .mono { font-family: 'Courier New', Courier, monospace; font-size: 10px; }

        /* colores semánticos que se imprimen */
        .c-red    { color: #b91c1c; }
        .c-amber  { color: #92400e; }
        .c-green  { color: #15803d; }
        .c-blue   { color: #1d4ed8; }
        .c-gray   { color: #6b7280; }
        .c-bold   { font-weight: 700; }

        /* badges inline */
        .badge {
            display: inline-block;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            border-radius: 3px;
            padding: 1px 5px;
        }
        .badge-red    { background: #fee2e2; color: #991b1b; }
        .badge-amber  { background: #fef3c7; color: #78350f; }
        .badge-green  { background: #dcfce7; color: #166534; }

        /* medal col */
        .medal { font-size: 13px; }

        /* ===================================================================
           TABLA TOTALES (recomendados)
        =================================================================== */
        .totals-row td {
            background: #f9fafb;
            font-weight: 700;
        }

        /* ===================================================================
           VACÍO
        =================================================================== */
        .empty-msg {
            text-align: center;
            padding: 16px;
            color: #888;
            font-style: italic;
            font-size: 11px;
            border: 1px dashed #ccc;
            border-radius: 4px;
        }

        /* ===================================================================
           PIE DE PÁGINA
        =================================================================== */
        .page-footer {
            margin-top: 48px;
            border-top: 1px solid #ccc;
            padding-top: 12px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .signature-area {
            display: flex;
            gap: 60px;
        }

        .signature-box {
            text-align: center;
            min-width: 120px;
        }

        .signature-line {
            margin-top: 50px;
            border-top: 1px solid #aaa;
            width: 100%;
        }

        .signature-label {
            font-size: 9px;
            color: #666;
            margin-top: 3px;
        }

        .footer-note {
            font-size: 9px;
            color: #aaa;
            text-align: right;
            align-self: flex-end;
        }

        /* ===================================================================
           BOTÓN IMPRIMIR (solo en pantalla)
        =================================================================== */
        .btn-print {
            background: #1d4ed8;
            color: #fff;
            border: none;
            padding: 10px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 24px;
        }
        .btn-print:hover { background: #1e40af; }

        /* ===================================================================
           PRINT OVERRIDES
        =================================================================== */
        @media print {
            @page {
                size: letter portrait;
                margin: 15mm 15mm 20mm 15mm;
            }

            body {
                padding: 0;
                margin: 0;
                font-size: 10px;
            }

            .no-print { display: none !important; }

            * {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .section { page-break-inside: avoid; }

            .section-header.low-stock { background: #fef3c7 !important; }
            .section-header.top       { background: #dbeafe !important; }
            .section-header.no-sales  { background: #f3f4f6 !important; }
            .section-header.buy       { background: #dcfce7 !important; }

            .badge-red   { background: #fee2e2 !important; }
            .badge-amber { background: #fef3c7 !important; }
            .badge-green { background: #dcfce7 !important; }
        }
    </style>
</head>
<body>

    {{-- ================================================================
         Botón imprimir (solo pantalla)
    ================================================================ --}}
    <div class="no-print" style="text-align:right; margin-bottom:20px;">
        <button onclick="window.print()" class="btn-print">🖨️ Imprimir Informe</button>
    </div>

    {{-- ================================================================
         CABECERA
    ================================================================ --}}
    <div class="page-header">
        <div class="company-block">
            @if($logoUrl)
                <img src="{{ $logoUrl }}" alt="Logo" class="company-logo">
            @endif
            <div>
                <p class="company-name">{{ $storeName }}</p>
                @if($storeAddress)
                    <p class="company-detail">{{ $storeAddress }}</p>
                @endif
                @if($storePhone)
                    <p class="company-detail">Tel: {{ $storePhone }}</p>
                @endif
                @if($storeNit)
                    <p class="company-detail">NIT: {{ $storeNit }}</p>
                @endif
            </div>
        </div>

        <div class="report-meta">
            <div class="report-title">Informe de Productos</div>
            <p class="meta-line">Período: <strong>{{ $periodLabel }}</strong></p>
            <p class="meta-line">Impreso: {{ $printedAt }}</p>
            <p class="meta-line">Por: <strong>{{ $printedBy }}</strong></p>
        </div>
    </div>

    {{-- ================================================================
         SECCIÓN 1 — Bajo stock
    ================================================================ --}}
    <div class="section">
        <div class="section-header low-stock">
            <span class="section-title">⚠️ Productos con Bajo Stock</span>
            <span class="section-badge">{{ $lowStock->count() }}</span>
        </div>

        @if($lowStock->isEmpty())
            <p class="empty-msg">✅ Todos los productos tienen stock suficiente.</p>
        @else
            <p class="section-note">Productos activos con cantidad igual o por debajo del mínimo establecido.</p>
            <table>
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>SKU</th>
                        <th>Categoría</th>
                        <th class="text-right">Stock actual</th>
                        <th class="text-right">Mínimo</th>
                        <th class="text-right">Déficit</th>
                        <th class="text-center">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($lowStock as $p)
                        @php $deficit = $p->min_stock - $p->quantity; @endphp
                        <tr>
                            <td class="c-bold">{{ $p->name }}</td>
                            <td class="mono">{{ $p->sku }}</td>
                            <td>{{ $p->category?->name ?? '—' }}</td>
                            <td class="text-right {{ $p->quantity <= 0 ? 'c-red c-bold' : 'c-amber c-bold' }}">
                                {{ $p->quantity }}
                            </td>
                            <td class="text-right">{{ $p->min_stock }}</td>
                            <td class="text-right c-red c-bold">{{ $deficit > 0 ? '-'.$deficit : '0' }}</td>
                            <td class="text-center">
                                @if($p->quantity <= 0)
                                    <span class="badge badge-red">Sin stock</span>
                                @else
                                    <span class="badge badge-amber">Bajo mínimo</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- ================================================================
         SECCIÓN 2 — Más vendidos
    ================================================================ --}}
    <div class="section">
        <div class="section-header top">
            <span class="section-title">🏆 Productos Más Vendidos</span>
            <span class="section-badge">{{ $topSelling->count() }}</span>
        </div>

        @if($topSelling->isEmpty())
            <p class="empty-msg">Sin ventas registradas en el período seleccionado.</p>
        @else
            <p class="section-note">Ranking por unidades vendidas en el período. Mismos datos que el ranking del panel principal.</p>
            <table>
                <thead>
                    <tr>
                        <th width="4%" class="text-center">#</th>
                        <th>Producto</th>
                        <th>SKU</th>
                        <th class="text-right">Unidades vendidas</th>
                        <th class="text-right">Ingresos generados</th>
                        <th class="text-right">Stock actual</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($topSelling as $i => $item)
                        <tr>
                            <td class="text-center">
                                @if($i === 0) <span class="medal">🥇</span>
                                @elseif($i === 1) <span class="medal">🥈</span>
                                @elseif($i === 2) <span class="medal">🥉</span>
                                @else <span class="c-gray">{{ $i + 1 }}</span>
                                @endif
                            </td>
                            <td class="c-bold">{{ $item->product?->name ?? '—' }}</td>
                            <td class="mono">{{ $item->product?->sku ?? '—' }}</td>
                            <td class="text-right c-bold">{{ number_format($item->total_qty) }}</td>
                            <td class="text-right c-green c-bold">{{ format_money($item->total_revenue) }}</td>
                            <td class="text-right">{{ $item->product?->quantity ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" class="text-right c-gray">Totales:</td>
                        <td class="text-right c-bold">{{ number_format($topSelling->sum('total_qty')) }} unid.</td>
                        <td class="text-right c-green c-bold">{{ format_money($topSelling->sum('total_revenue')) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        @endif
    </div>

    {{-- ================================================================
         SECCIÓN 3 — Sin movimiento
    ================================================================ --}}
    <div class="section">
        <div class="section-header no-sales">
            <span class="section-title">🕳️ Productos Sin Movimiento</span>
            <span class="section-badge">{{ $noSales->count() }}</span>
        </div>

        @if($noSales->isEmpty())
            <p class="empty-msg">🎉 Todos los productos activos tuvieron ventas en el período.</p>
        @else
            <p class="section-note">Productos activos sin ninguna venta en el período. Revisar precio, visibilidad o considerar discontinuar.</p>
            <table>
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>SKU</th>
                        <th>Categoría</th>
                        <th class="text-right">Stock actual</th>
                        <th class="text-right">Precio de venta</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($noSales as $p)
                        <tr>
                            <td class="c-bold">{{ $p->name }}</td>
                            <td class="mono">{{ $p->sku }}</td>
                            <td>{{ $p->category?->name ?? '—' }}</td>
                            <td class="text-right {{ $p->quantity <= 0 ? 'c-red' : '' }}">{{ $p->quantity }}</td>
                            <td class="text-right">{{ format_money($p->selling_price) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- ================================================================
         SECCIÓN 4 — Recomendados para compra
    ================================================================ --}}
    <div class="section">
        <div class="section-header buy">
            <span class="section-title">🛒 Recomendados para Compra</span>
            <span class="section-badge">{{ $recommended->count() }}</span>
        </div>

        @if($recommended->isEmpty())
            <p class="empty-msg">✅ No hay productos que requieran reabastecimiento urgente en este momento.</p>
        @else
            <p class="section-note">Productos con demanda activa y stock bajo. La cantidad sugerida cubre 30 días según velocidad de venta en el período analizado.</p>
            <table>
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>Categoría</th>
                        <th class="text-right">Stock</th>
                        <th class="text-right">Mín.</th>
                        <th class="text-right">Vendidos</th>
                        <th class="text-right">Días de stock</th>
                        <th class="text-right">Sugerido</th>
                        <th class="text-right">Costo estimado</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recommended as $p)
                        <tr>
                            <td>
                                <span class="c-bold">{{ $p->name }}</span><br>
                                <span class="mono c-gray">{{ $p->sku }}</span>
                            </td>
                            <td>{{ $p->category }}</td>
                            <td class="text-right {{ $p->quantity <= 0 ? 'c-red c-bold' : 'c-amber c-bold' }}">
                                {{ $p->quantity }}
                            </td>
                            <td class="text-right">{{ $p->min_stock }}</td>
                            <td class="text-right">{{ $p->total_sold }}</td>
                            <td class="text-right">
                                @if($p->days_of_stock !== null)
                                    <span class="{{ $p->days_of_stock <= 7 ? 'c-red c-bold' : ($p->days_of_stock <= 14 ? 'c-amber c-bold' : '') }}">
                                        {{ $p->days_of_stock }} días
                                    </span>
                                @else
                                    <span class="c-gray">—</span>
                                @endif
                            </td>
                            <td class="text-right c-bold" style="font-size:12px;">{{ $p->suggested }}</td>
                            <td class="text-right">{{ format_money($p->purchase_cost) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="totals-row">
                        <td colspan="6" class="text-right">Costo total estimado de reabastecimiento:</td>
                        <td></td>
                        <td class="text-right c-bold" style="font-size:12px;">
                            {{ format_money($recommended->sum('purchase_cost')) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        @endif
    </div>

    {{-- ================================================================
         PIE DE PÁGINA con firmas
    ================================================================ --}}
    <div class="page-footer">
        <div class="signature-area">
            <div class="signature-box">
                <div class="signature-line"></div>
                <p class="signature-label">Elaborado por<br><strong>{{ $printedBy }}</strong></p>
            </div>
            <div class="signature-box">
                <div class="signature-line"></div>
                <p class="signature-label">Revisado por<br>&nbsp;</p>
            </div>
            <div class="signature-box">
                <div class="signature-line"></div>
                <p class="signature-label">Aprobado por<br>&nbsp;</p>
            </div>
        </div>

        <div class="footer-note">
            Generado: {{ $printedAt }}<br>
            Sistema de inventario — {{ $storeName }}
        </div>
    </div>

</body>
</html>
