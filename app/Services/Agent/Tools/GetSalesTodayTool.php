<?php

namespace App\Services\Agent\Tools;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\Agent\AgentContext;
use App\Services\Agent\BaseTool;
use Illuminate\Support\Facades\DB;

class GetSalesTodayTool extends BaseTool
{
    public function name(): string
    {
        return 'get_sales_today';
    }

    public function description(): string
    {
        return 'Resumen de ventas para una fecha o período: total, transacciones, ticket promedio, ganancia bruta. Soporta fecha exacta (YYYY-MM-DD) o días_atras (0=hoy, 1=ayer, 7=semana).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date' => [
                    'type' => 'string',
                    'description' => 'Fecha exacta en formato YYYY-MM-DD. Default: hoy.',
                ],
                'days_ago' => [
                    'type' => 'integer',
                    'description' => 'Días hacia atrás desde hoy (0=hoy, 1=ayer, 7=últimos 7 días).',
                ],
            ],
        ];
    }

    public function execute(array $input, AgentContext $context): array
    {
        if (!empty($input['date'])) {
            $dateStr = (string) $input['date'];

            // Validar formato estricto YYYY-MM-DD. El LLM puede enviar "ayer", "21/05/2026",
            // o cualquier basura — sin validar Eloquent intenta el query y puede romper o devolver 0.
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
                return ['error' => "Formato de fecha inválido: '{$dateStr}'. Usa YYYY-MM-DD."];
            }
            $parsed = \DateTime::createFromFormat('Y-m-d', $dateStr);
            if (!$parsed || $parsed->format('Y-m-d') !== $dateStr) {
                return ['error' => "Fecha inexistente: '{$dateStr}'."];
            }

            $label   = $dateStr;
            $sales   = Sale::whereDate('sale_date', $dateStr)->where('status', 'completed')->get();
        } elseif (isset($input['days_ago']) && (int) $input['days_ago'] > 0) {
            $daysAgo = min((int) $input['days_ago'], 365);
            $from    = now()->subDays($daysAgo)->toDateString();
            $label   = "últimos {$daysAgo} días";
            $sales   = Sale::where('sale_date', '>=', $from)->where('status', 'completed')->get();
        } else {
            $dateStr = now()->toDateString();
            $label   = $dateStr;
            $sales   = Sale::whereDate('sale_date', $dateStr)->where('status', 'completed')->get();
        }

        $total = $sales->sum('total');
        $count = $sales->count();
        $cost  = $count > 0
            ? SaleItem::whereIn('sale_id', $sales->pluck('id'))
                ->sum(DB::raw('cost_price * quantity'))
            : 0;

        return [
            'period'         => $label,
            'total_bs'       => round($total / 100, 2),
            'transactions'   => $count,
            'avg_ticket_bs'  => $count > 0 ? round(($total / $count) / 100, 2) : 0,
            'cost_bs'        => round($cost / 100, 2),
            'gross_profit_bs'=> round(($total - $cost) / 100, 2),
        ];
    }
}
