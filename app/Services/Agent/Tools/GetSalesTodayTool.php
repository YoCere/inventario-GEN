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
        return 'Resumen de ventas del día: total, número de transacciones, ticket promedio, ganancia bruta.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function execute(array $input, AgentContext $context): array
    {
        $today = now()->toDateString();
        $sales = Sale::whereDate('sale_date', $today)
            ->where('status', 'completed')
            ->get();

        $total = $sales->sum('total');
        $count = $sales->count();
        $cost = SaleItem::whereIn('sale_id', $sales->pluck('id'))
            ->sum(DB::raw('cost_price * quantity'));

        return [
            'date' => $today,
            'total_bs' => round($total / 100, 2),
            'transactions' => $count,
            'avg_ticket_bs' => $count > 0 ? round(($total / $count) / 100, 2) : 0,
            'cost_bs' => round($cost / 100, 2),
            'gross_profit_bs' => round(($total - $cost) / 100, 2),
        ];
    }
}
