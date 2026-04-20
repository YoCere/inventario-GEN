<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planilla {{ $sheet->sheet_number }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; color: #111827; margin: 20px; }
        h1, h2 { margin: 0; }
        .meta { margin: 12px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #d1d5db; padding: 6px; }
        th { background: #f3f4f6; text-align: left; }
        .right { text-align: right; }
    </style>
</head>
<body>
    <h1>Planilla de sueldos</h1>
    <h2>{{ $sheet->sheet_number }}</h2>

    <div class="meta">
        <p><strong>Periodo:</strong> {{ $sheet->period_month?->format('m/Y') }}</p>
        <p><strong>Fecha de pago:</strong> {{ $sheet->payment_date?->format('d/m/Y') }}</p>
        <p><strong>Estado:</strong> {{ $sheet->status->label() }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Trabajador</th>
                <th>Area</th>
                <th class="right">Total ganado</th>
                <th class="right">Total descuentos</th>
                <th class="right">Liquido pagable</th>
                <th class="right">Costo total empleador</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sheet->items as $item)
                <tr>
                    <td>{{ $item->line_number }}</td>
                    <td>{{ $item->employee_name }}</td>
                    <td>{{ strtoupper($item->area) }}</td>
                    <td class="right">{{ number_format($item->total_earned, 2, '.', ',') }}</td>
                    <td class="right">{{ number_format($item->total_deductions, 2, '.', ',') }}</td>
                    <td class="right">{{ number_format($item->net_payable, 2, '.', ',') }}</td>
                    <td class="right">{{ number_format($item->total_employer_cost, 2, '.', ',') }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="3" class="right">Totales</th>
                <th class="right">{{ number_format($sheet->total_earned, 2, '.', ',') }}</th>
                <th class="right">{{ number_format($sheet->total_deductions, 2, '.', ',') }}</th>
                <th class="right">{{ number_format($sheet->net_payable, 2, '.', ',') }}</th>
                <th class="right">{{ number_format($sheet->total_employer_cost, 2, '.', ',') }}</th>
            </tr>
        </tfoot>
    </table>

    <script>
        window.print();
    </script>
</body>
</html>

